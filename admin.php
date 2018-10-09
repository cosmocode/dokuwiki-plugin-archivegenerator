<?php

use splitbrain\PHPArchive\Zip;

/**
 * DokuWiki Plugin archivegenerator (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Große <dokuwiki@cosmocode.de>
 */

class admin_plugin_archivegenerator extends DokuWiki_Admin_Plugin
{

    protected $generateArchive = false;

    /**
     * @return int sort number in admin menu
     */
    public function getMenuSort()
    {
        return 123;
    }

    /**
     * @return bool true if only access for superuser, false is for superusers and moderators
     */
    public function forAdminOnly()
    {
        return true;
    }

    /**
     * Should carry out any processing required by the plugin.
     */
    public function handle()
    {
        global $INPUT;

        if ($INPUT->bool('downloadArchive') && checkSecurityToken($INPUT->str('sectok'))) {
            $this->sendArchiveAndExit();
        }

        if ($INPUT->server->str('REQUEST_METHOD') !== 'POST') {
            return;
        }


        $sectok = $INPUT->post->str('sectok');
        if (!checkSecurityToken($sectok)) {
            return;
        }
        $email = $INPUT->post->str('adminMail');
        $pass = $INPUT->post->str('adminPass');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            msg(sprintf($this->getLang('message: email invalid'), hsc($email)), -1);
            return;
        }

        if (empty($pass)) {
            msg($this->getLang('message: password empty'), -1);
            return;
        }

        $this->generateArchive = true;
    }

    protected function sendArchiveAndExit()
    {
        global $conf;
        $persistentArchiveFN = $conf['tmpdir'] . '/archivegenerator/archive.zip';
        header('Content-Disposition: attachment; filename="archive.zip"');
        readfile($persistentArchiveFN);
        exit();
    }

    protected function generateArchive()
    {
        global $conf, $INPUT;
        $this->log('info', $this->getLang('message: starting'));
        $tmpArchiveFN = $conf['tmpdir'] . '/archivegenerator/archive_new.zip';
        $archive = $this->createZipArchive($tmpArchiveFN);
        $this->addDirToArchive($archive, '.', false);
        $this->addDirToArchive($archive, 'inc');
        $this->addDirToArchive($archive, 'bin');
        $this->addDirToArchive($archive, 'vendor');
        $this->addDirToArchive($archive, 'conf', true, '(^users\.auth\.php$|^acl\.auth\.php$)');
        $this->addUsersAuthToArchive($archive);
        $this->addACLToArchive($archive);
        $this->addDirToArchive($archive, 'lib', true, $INPUT->post->str('skipPluginsRegex'));
        $this->addDirToArchive($archive, 'data/pages');
        $this->addDirToArchive($archive, 'data/meta', true, '\.changes(\.trimmed)?$');
        $this->addDirToArchive($archive, 'data/media');
        $this->addDirToArchive($archive, 'data/media_meta', true, '\.changes$');
        $this->addDirToArchive($archive, 'data/index');

        $this->addEmptyDirToArchive($archive, 'data/attic');
        $this->addEmptyDirToArchive($archive, 'data/cache');
        $this->addEmptyDirToArchive($archive, 'data/locks');
        $this->addEmptyDirToArchive($archive, 'data/tmp');
        $this->addEmptyDirToArchive($archive, 'data/media_attic');

        $archive->close();
        $this->log('info', $this->getLang('message: adding data done'));

        $persistentArchiveFN = $conf['tmpdir'] . '/archivegenerator/archive.zip';
        io_rename($tmpArchiveFN, $persistentArchiveFN);

        $href = $this->getDownloadLinkHref();
        $link = "<a href=\"$href\">" . $this->getLang('link: download now') . '</a>';
        $this->log('success', $this->getLang('message: done') . ' ' . $link);
    }

    protected function getDownloadLinkHref()
    {
        global $INPUT;
        return $INPUT->server->str('REQUEST_URI') . '&downloadArchive=1&sectok=' . getSecurityToken();
    }

    protected function addEmptyDirToArchive(Zip $archive, $directory)
    {
        $this->log('info', sprintf($this->getLang('message: create empty dir'), $directory));
        $dirPath = $directory . '/.keep';
        $archive->addData($dirPath, '');
    }

    protected function addUsersAuthToArchive(Zip $archive)
    {
        global $INPUT;

        $email = $INPUT->post->str('adminMail');
        $pass = $INPUT->post->str('adminPass');

        $this->log('info', $this->getLang('message: create users'));
        $authFile = '
# users.auth.php
# <?php exit()?>
# Don\'t modify the lines above
#
# Userfile
#
# Format:
#
# login:passwordhash:Real Name:email:groups,comma,separated

        ';

        $pwHash = auth_cryptPassword($pass);
        $adminLine = "admin:$pwHash:Administrator:$email:users,admin\n";
        $archive->addData('conf/users.auth.php', $authFile . $adminLine);
    }

    protected function addACLToArchive(Zip $archive)
    {
        $this->log('info', $this->getLang('message: create acl'));
        $aclFileContents = '# acl.auth.php
# <?php exit()?>
*  @ALL   0
*  @users 1
';
        $archive->addData('conf/acl.auth.php', $aclFileContents);
    }

    /**
     * @return Zip
     * @throws \splitbrain\PHPArchive\ArchiveIOException
     */
    protected function createZipArchive($archiveFN)
    {
        $this->log('info', sprintf($this->getLang('message: create zip archive'), hsc($archiveFN)));
        $zip = new Zip();
        $zip->create($archiveFN);

        return $zip;
    }

    protected function addDirToArchive(Zip $archive, $srcDir, $recursive = true, $skipRegex = null)
    {
        $message = [];
        $message[] = sprintf($this->getLang('message: add files in dir'), hsc($srcDir . '/'));
        if ($recursive) {
            $message[] = $this->getLang('message: recursive');
        }
        if ($skipRegex) {
            $message[] = sprintf($this->getLang('message: skipping files'), hsc($skipRegex));
        }
        $message[] .= '...';
        $this->log('info', implode(' ', $message));
        $this->addFilesToArchive(DOKU_INC . $srcDir, $archive, !$recursive, $skipRegex);
    }

    protected function addFilesToArchive($source, Zip $archive, $filesOnly = false, $skipRegex = null)
    {
        // Simple copy for a file
        if (is_file($source)) {
            if (filesize($source) > 50000000) {
                $this->log('warning', sprintf($this->getLang('message: file is large'), hsc($source)) . ' ' . filesize_h(filesize($source)));
            }

            $dwPathName = substr($source, strlen(DOKU_INC));
            try {
                $archive->addFile($source, $dwPathName);
            } catch (\splitbrain\PHPArchive\ArchiveIOException $e) {
                $this->log('error', hsc($e->getMessage()));
                return false;
            }
            return true;
        }

        // Loop through the folder
        $dir = dir($source);
        while (false !== $entry = $dir->read()) {
            if (in_array($entry, ['.', '..', '.git', 'node_modules'])) {
                continue;
            }

            if ($skipRegex && preg_match("/$skipRegex/", $entry)) {
                continue;
            }

            $srcFN = "$source/$entry";
            if (is_dir($srcFN) && $filesOnly) {
                continue;
            }

            $copyOK = $this->addFilesToArchive($srcFN, $archive, $filesOnly, $skipRegex);
            if ($copyOK === false) {
                return false;
            }
        }

        // Clean up
        $dir->close();
        return true;
    }

    /**
     * Render HTML output, e.g. helpful text and a form
     */
    public function html()
    {
        ptln('<h1>' . $this->getLang('menu') . '</h1>');
        echo $this->locale_xhtml('intro');

        if ($this->generateArchive) {
            $this->generateArchive();
        } else {
            $this->showForm();
        }
    }


    protected function showForm()
    {
        global $conf;

        $persistentArchiveFN = $conf['tmpdir'] . '/archivegenerator/archive.zip';
        if (file_exists($persistentArchiveFN)) {
            $mtime = dformat(filemtime($persistentArchiveFN));
            $href = $this->getDownloadLinkHref();
            $link = "<a href=\"$href\">" . $this->getLang('link: download now') . '</a>';

            $message = sprintf($this->getLang('message: archive exists'), $mtime) . ' ' . $link;
            msg($message, 2);
        }

        $form = new \dokuwiki\Form\Form();
        $form->addFieldsetOpen();

        $adminMailInput = $form->addTextInput('adminMail', $this->getLang('label: admin mail'));
        $adminMailInput->addClass('block');
        $adminMailInput->attrs(['type' => 'email', 'required' => '1']);

        $adminPassInput = $form->addPasswordInput('adminPass', $this->getLang('label: admin pass'));
        $adminPassInput->addClass('block');
        $adminPassInput->attr('required', 1);

        $skipPluginsInput = $form->addTextInput('skipPluginsRegex', $this->getLang('label: plugin skip regex'));
        $skipPluginsInput->val('^archivegenerator$');
        $skipPluginsInput->addClass('block');

        $form->addButton('submit', $this->getLang('button: generate archive'));

        $form->addFieldsetClose();
        echo $form->toHTML();
    }

    protected function log($level, $message) {
        static $startTime;
        if (!$startTime) {
            $startTime = microtime(true);
        }

        $time = round(microtime(true) - $startTime, 3);
        $timedMessage = sprintf($this->getLang('seconds'), $time) . ': ' . $message;

        switch ($level) {
            case 'error':
                $msgLVL = -1;
                break;
            case 'warning':
                $msgLVL = 2;
                break;
            case 'success':
                $msgLVL = 1;
                break;
            default:
                $msgLVL = 0;
        }

        msg($timedMessage, $msgLVL);
        echo str_repeat(' ', 16*1024);
        flush();
        ob_flush();
    }
}

