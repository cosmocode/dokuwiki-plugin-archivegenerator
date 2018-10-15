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
    protected $base = 'dokuwiki/';

    /** @inheritdoc */
    public function getMenuSort()
    {
        return 123;
    }

    /** @inheritdoc */
    public function forAdminOnly()
    {
        return true;
    }

    /** @inheritdoc */
    public function handle()
    {
        global $INPUT;

        if ($INPUT->bool('downloadArchive') && checkSecurityToken()) {
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

    /** @inheritdoc */
    public function html()
    {
        if (!$this->generateArchive) {
            $this->downloadView();

            ptln('<h1>' . $this->getLang('menu') . '</h1>');
            echo $this->locale_xhtml('intro');
        } else {
            ptln('<h1>' . $this->getLang('menu') . '</h1>');
            try {
                $this->generateArchive();
                return;
            } catch (\splitbrain\PHPArchive\ArchiveIOException $e) {
                msg(hsc($e->getMessage()), -1);
            }
        }
        $this->showForm();
    }

    /**
     * Send the existing wiki archive file and exit
     */
    protected function sendArchiveAndExit()
    {
        global $conf;
        $persistentArchiveFN = $conf['tmpdir'] . '/archivegenerator/archive.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="archive.zip"');
        http_sendfile($persistentArchiveFN);
        readfile($persistentArchiveFN);
        exit();
    }

    /**
     * Build the archive based on the existing wiki
     *
     * @throws \splitbrain\PHPArchive\ArchiveIOException
     */
    protected function generateArchive()
    {
        global $conf;
        $this->log('info', $this->getLang('message: starting'));
        $tmpArchiveFN = $conf['tmpdir'] . '/archivegenerator/archive_new.zip';
        $archive = $this->createZipArchive($tmpArchiveFN);
        set_time_limit(0);
        $this->addDirToArchive($archive, '.', false);
        $this->addDirToArchive($archive, 'inc');
        $this->addDirToArchive($archive, 'bin');
        $this->addDirToArchive($archive, 'vendor');
        $this->addDirToArchive($archive, 'conf', true, '^' . $this->base . 'conf/(users\.auth\.php|acl\.auth\.php)$');
        $this->addUsersAuthToArchive($archive);
        $this->addACLToArchive($archive);
        $this->addDirToArchive($archive, 'lib', true, '^' . $this->base . 'lib/plugins$');
        $this->addDirToArchive($archive, 'lib/plugins', true, $this->buildSkipPluginRegex());
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

        // try a redirect to self
        ptln('<script type="text/javascript">window.location.href=\'' . $this->getSelfRedirect() . '\';</script>');
    }

    /**
     * Build a regex for the plugins to skip, relative to the DokuWiki root
     *
     * @return string
     */
    protected function buildSkipPluginRegex()
    {
        $list = array_map('trim', explode(',', $this->getConf('pluginsToIgnore')));
        return '^' . $this->base . 'lib/plugins/(' . implode('|', $list) . ')$';
    }

    /**
     * Generate a href for a link to download the archive
     *
     * @return string
     */
    protected function getDownloadLinkHref()
    {
        global $ID;
        return wl($ID, [
            'do' => 'admin',
            'page' => 'archivegenerator',
            'downloadArchive' => 1,
            'sectok' => getSecurityToken(),
        ]);
    }

    /**
     * Generate the link to the admin page itself
     *
     * @return string
     */
    protected function getSelfRedirect()
    {
        global $ID;
        return wl($ID, [
            'do' => 'admin',
            'page' => 'archivegenerator',
        ], false, '&');
    }

    /**
     * Add an empty directory to the archive.
     *
     * The directory will contain a dummy .keep file.
     *
     * @param Zip $archive
     * @param string $directory path of the directory to add relative to the dokuwiki root
     *
     * @throws \splitbrain\PHPArchive\ArchiveIOException
     */
    protected function addEmptyDirToArchive(Zip $archive, $directory)
    {
        $this->log('info', sprintf($this->getLang('message: create empty dir'), $directory));
        $dirPath = $this->base . $directory . '/.keep';
        $archive->addData($dirPath, '');
    }

    /**
     * Create a users.auth.php file with a single admin user
     *
     * @param Zip $archive
     *
     * @throws \splitbrain\PHPArchive\ArchiveIOException
     */
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
        $archive->addData($this->base . 'conf/users.auth.php', $authFile . $adminLine);
    }

    /**
     * Create an acl.auth.php file that allows reading only for logged-in users
     *
     * @param Zip $archive
     *
     * @throws \splitbrain\PHPArchive\ArchiveIOException
     */
    protected function addACLToArchive(Zip $archive)
    {
        $this->log('info', $this->getLang('message: create acl'));
        $aclFileContents = '# acl.auth.php
# <?php exit()?>
*  @ALL   0
*  @users 1
';
        $archive->addData($this->base . 'conf/acl.auth.php', $aclFileContents);
    }

    /**
     * Create the archive file
     *
     * @return Zip
     * @throws \splitbrain\PHPArchive\ArchiveIOException
     */
    protected function createZipArchive($archiveFN)
    {
        $this->log('info', sprintf($this->getLang('message: create zip archive'), hsc($archiveFN)));
        io_makeFileDir($archiveFN);
        $zip = new Zip();
        $zip->create($archiveFN);

        return $zip;
    }

    /**
     * Add the contents of an directory to the archive
     *
     * @param Zip $archive
     * @param string $srcDir the directory relative to the dokuwiki root
     * @param bool $recursive whether to add subdirectories as well
     * @param null|string $skipRegex files and directories matching this regex will be ignored. no delimiters
     *
     * @throws \splitbrain\PHPArchive\ArchiveIOException
     */
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

    /**
     * Recursive method to add files and directories to a archive
     *
     * It will report large files that might cause the process to fail.
     *
     * @param string $source
     * @param Zip $archive
     * @param bool $filesOnly
     * @param null $skipRegex
     *
     * @return bool
     * @throws \splitbrain\PHPArchive\ArchiveIOException
     */
    protected function addFilesToArchive($source, Zip $archive, $filesOnly = false, $skipRegex = null)
    {
        // Simple copy for a file
        if (is_file($source)) {
            if (filesize($source) > 50 * 1024 * 1024) {
                $this->log('warning', sprintf($this->getLang('message: file is large'),
                        hsc($source)) . ' ' . filesize_h(filesize($source)));
            }

            try {
                $archive->addFile($source, $this->getDWPathName($source));
            } catch (\splitbrain\PHPArchive\ArchiveIOException $e) {
                $this->log('error', hsc($e->getMessage()));
                throw $e;
            }
            return true;
        }

        // Loop through the folder
        $dir = dir($source);
        while (false !== $entry = $dir->read()) {
            if (in_array($entry, ['.', '..', '.git', 'node_modules'])) {
                continue;
            }
            $srcFN = "$source/$entry";

            if ($skipRegex && preg_match("#$skipRegex#", $this->getDWPathName($srcFN))) {
                continue;
            }

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
     * Get the filepath relative to the dokuwiki root
     *
     * @param $filepath
     *
     * @return string
     */
    protected function getDWPathName($filepath)
    {
        return $this->base . substr($filepath, strlen(DOKU_INC));
    }

    /**
     * Display the download info
     */
    protected function downloadView()
    {
        global $conf;

        $persistentArchiveFN = $conf['tmpdir'] . '/archivegenerator/archive.zip';
        if (!file_exists($persistentArchiveFN)) return;

        ptln('<h1>' . $this->getLang('label: download') . '</h1>');

        $mtime = dformat(filemtime($persistentArchiveFN));
        $href = $this->getDownloadLinkHref();

        ptln('<p>' . sprintf($this->getLang('message: archive exists'), $mtime) . '</p>');
        ptln("<p><a href=\"$href\">" . $this->getLang('link: download now') . '</a></p>');
    }

    /**
     * Show the default form
     */
    protected function showForm()
    {
        $form = new \dokuwiki\Form\Form();
        $form->addFieldsetOpen();

        $adminMailInput = $form->addTextInput('adminMail', $this->getLang('label: admin mail'));
        $adminMailInput->addClass('block');
        $adminMailInput->attrs(['type' => 'email', 'required' => '1']);

        $adminPassInput = $form->addPasswordInput('adminPass', $this->getLang('label: admin pass'));
        $adminPassInput->addClass('block');
        $adminPassInput->attr('required', 1);

        $form->addButton('submit', $this->getLang('button: generate archive'));

        $form->addFieldsetClose();
        echo $form->toHTML();
    }

    /**
     * Print a message to the user, prefixes the time since the first message
     *
     * This adds whitespace padding to force the message being printed immediately.
     *
     * @param string $level can be 'error', 'warning' or 'info'
     * @param string $message
     */
    protected function log($level, $message)
    {
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
        echo str_repeat(' ', 16 * 1024);

        /** @noinspection MissingOrEmptyGroupStatementInspection */
        /** @noinspection LoopWhichDoesNotLoopInspection */
        /** @noinspection PhpStatementHasEmptyBodyInspection */
        while (@ob_end_flush()) {
        };
        flush();
    }
}

