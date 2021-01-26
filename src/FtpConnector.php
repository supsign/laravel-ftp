<?php

namespace Supsign\LaravelFtpConnector;

class FtpConnector {
    protected 
        $connection = null,
        $dateFormat = 'D.m.Y H:i:s',
        $file = null,
        $files = null,
        $folder = null,
        $ftpConnections = [],
        $localDirectory = null,
        $localFile = null,
        $localRootDirectory = '/',
        $login = null,
        $remoteDirectory = '/',
        $remoteFile = null,
        $password = null,
        $port = null,
        $protocol = null,
        $server = null,
        $user = null;

    public function __construct($ftpData) {
        $this->protocol = env($ftpData.'_FTP_MODE');
        $this->server = env($ftpData.'_FTP_HOST');
        $this->port = env($ftpData.'_FTP_PORT');
        $this->user = env($ftpData.'_FTP_LOGIN');
        $this->password = env($ftpData.'_FTP_PASSWORD');
        $this->passive = env($ftpData.'_FTP_PASSIVE') ?: false;

        return $this->connect()->login();
    }

    protected function connect() {
        switch ($this->protocol) {
            case 'FTP':
                $this->connection = ftp_connect($this->server, (int)$this->port);
                ftp_pasv($this->connection, $this->passive);
                ftp_set_option($this->connection, FTP_TIMEOUT_SEC, 1200);
                break;
            
            case 'SFTP':
            default:
                $this->connection = ssh2_connect($this->server, (int)$this->port);
                break;
        }

        if (!$this->connection)
            throw new \Exception('Could not connect to "'.$this->server.'" on port "'.$this->port.'".');

        return $this;
    }

    protected function disconnect() {
    }

    protected function delteFile($source) {
        switch (true) {
            case $this->protocol = 'FTP' AND $source == 'remote':
                ftp_delete($this->getFilePath($source));
                break;
            
            default:
            case 'SFTP':
                unlink($this->getFilePath($source));
                break;
        }

        return $this;
    }

    public function deleteLocalFile() {
        return $this->delteFile('local');
    }

    public function deleteRemoteFile() {
        return $this->delteFile('remote');
    }

    public function downloadFile() {
        switch ($this->protocol) {
            case 'FTP':
                ftp_get($this->connection, $this->localFile, $this->remoteFile);
                break;
            
            case 'SFTP':
                copy($this->remoteFile, $this->localFile);
                break;
        }

        return $this;
    }

    public function getFiles() {
        return array_keys(
            array_merge(
                array_flip($this->readLocalDirectory()),
                array_flip($this->readRemoteDirectory())
            )
        );  
    }

    protected function getFilePath($source) {
        switch ($source) {
            case 'local': return $this->localFile;
            case 'remote': return $this->remoteFile;
            default: throw new \Exception('invalid file source');
        }
    }

    protected function getFileHash($source) {
        return hash_file('md5', $this->getFilePath($source)) ;
    }

    protected function getFileTime($source, $format = null) {
        if (!file_exists($this->getFilePath($source)))
            return false;

        $filetime = filemtime($this->getFilePath($source));

        if ($format AND $filetime)
            return (new \DateTime())->setTimestamp($filetime)->format($format);

        return $filetime;
    }

    protected function getLocalFileHash(){
        return $this->getFileHash('local');
    }

    protected function getLocalFileTime($format = null) {
        return $this->getFileTime('local', $format);
    }

    protected function getRemoteFileHash(){
        return $this->getFileHash('remote');
    }

    protected function getRemoteFileTime($format = null) {
        return $this->getFileTime('remote', $format);
    }

    protected function fileExists($source) {
        return file_exists($this->getFilePath($source));
    }

    public function fileExistsLocal() {
        return $this->fileExists('local');
    }

    public function fileExistsRemote() {
        return $this->fileExists('remote');
    }

    protected function login() {
        if (!$this->connection)
            $this->connect();

        switch ($this->protocol) {
            case 'FTP':
                $this->login = ftp_login($this->connection, $this->user, $this->password);

                if (!$this->login)
                    throw new \Exception('Login failed.');
                    
                break;
                        
            case 'SFTP':
            default:
                if (!ssh2_auth_password($this->connection, $this->user, $this->password) )  //  SSH key variant? 
                    throw new \Exception('Could not authenticate with username '.$username.' and password '.$password);

                $this->login = ssh2_sftp($this->connection);

                if (!$this->login)
                    throw new \Exception('Could not initialize SFTP subsystem.');

                break;
        }

        return $this;
    }

    public function uploadFile() {
        switch ($this->protocol) {
            case 'FTP':
                ftp_put($this->connection, $this->remoteFile, $this->localFile);
                break;
            
            case 'SFTP':
                copy($this->localFile, $this->remoteFile);
                break;
        }

        return $this;
    }

    protected function readLocalDirectory() {
        return $this->readDirectory($this->localDirectory);
    }

    protected function readFile($source) {
        return file_get_contents($this->getFilePath($source));
    }

    protected function readLocalFile() {
        return $this->readFile('local');
    }

    public function readRemoteDirectory() {
        return $this->readDirectory($this->remoteDirectory);
    }

    protected function readRemoteFile() {
        return $this->readFile('remote');
    }

    public function setLocalDirectory($dir) {
        $this->localDirectory = $this->localRootDirectory.$dir;

        return $this;
    }

    public function setLocalFile($file) {
        $this->localFile = $file;

        return $this;
    }

    public function setLocalRootDirectory($dir) {
        $this->localRootDirectory = $dir;

        return $this;
    }

    public function setRemoteDirectory($dir) {
        switch ($this->protocol) {
            case 'SFTP':
                $this->remoteDirectory = 'ssh2.sftp://'.$this->login.$dir;
                break;
            
            default:
                $this->remoteDirectory = $dir;
                break;
        }

        return $this;
    }

    public function setRemoteFile($file) {
        $this->remoteFile = $file;

        return $this;
    }

    protected function readDirectory($dir) {        
        switch ($this->protocol) {
            case 'FTP':
                return ftp_nlist($this->connection, $dir);
                break;
            
            case 'SFTP':
                $files = [];

                if (file_exists($dir) AND is_dir($dir)) {
                    $currentDir = scandir($dir);

                    foreach ($currentDir AS $entry) {
                        if ($entry[0] == '.')
                            continue;

                        if ($entry[0] == '~')
                            continue;

                        if (is_dir($dir.$entry)) {
                            $entry .= '/';

                            foreach ($this->readDirectory($dir.$entry) AS $subEntry)
                                $files[] = $entry.$subEntry;

                            continue;
                        }

                        $files[] = $entry;
                    }
                }
                break;
        }

        return $files;
    }

    public function test()
    {

        $this->login();

        var_dump($this->connection, $this->remoteDirectory);


        // return ftp_get($this->connection, 'test.csv', 'test.csv');

        return ftp_nlist($this->connection, $this->remoteDirectory);




    }











}