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

    public function __destruct()
    {
        switch ($this->protocol) {
            case 'FTP':
                ftp_close($this->connection);
                break;
            
            case 'SFTP':
            default:
                ssh2_disconnect($this->connection);
                break;
        }
    }

    protected function connect(): self
    {
        switch ($this->protocol) {
            case 'FTP':
                $this->connection = ftp_connect($this->server, (int)$this->port);

                if (!$this->connection) {
                    throw new \Exception('Could not connect to "'.$this->server.':'.$this->port, 1);
                }

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

    protected function disconnect(): self
    {
        return $this;
    }

    protected function delteFile($source): self
    {
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

    public function deleteLocalFile(): self
    {
        return $this->delteFile('local');
    }

    public function deleteRemoteFile(): self
    {
        return $this->delteFile('remote');
    }

    public function downloadFile(): self
    {
        switch ($this->protocol) {
            case 'FTP':
                ftp_get($this->connection, $this->localFile, $this->remoteFile);
                break;
            
            case 'SFTP':
                copy($this->getFilePath('remote'), $this->getFilePath('local'));
                break;
        }

        return $this;
    }

    public function getFiles(): array
    {
        return array_keys(
            array_merge(
                array_flip($this->readLocalDirectory()),
                array_flip($this->readRemoteDirectory())
            )
        );  
    }

    protected function getFilePath($source): string
    {
        switch ($source) {
            case 'local': return $this->localDirectory.$this->localFile;
            case 'remote': return $this->remoteDirectory.$this->remoteFile;
            default: throw new \Exception('invalid file source');
        }
    }

    protected function getFileHash($source): string
    {
        return hash_file('md5', $this->getFilePath($source)) ;
    }

    protected function getFileTime($source, $format = null): int|string
    {
        if (!file_exists($this->getFilePath($source))) {
            return false;
        }

        $filetime = filemtime($this->getFilePath($source));

        if ($format AND $filetime) {
            return (new \DateTime())->setTimestamp($filetime)->format($format);
        }

        return $filetime;
    }

    protected function getLocalFileHash(): string
    {
        return $this->getFileHash('local');
    }

    protected function getLocalFileTime($format = null): int|string
    {
        return $this->getFileTime('local', $format);
    }

    protected function getRemoteFileHash(): string
    {
        return $this->getFileHash('remote');
    }

    protected function getRemoteFileTime($format = null): int|string
    {
        return $this->getFileTime('remote', $format);
    }

    protected function fileExists($source): bool
    {
        return file_exists($this->getFilePath($source));
    }

    public function fileExistsLocal(): bool
    {
        return $this->fileExists('local');
    }

    public function fileExistsRemote(): bool
    {
        return $this->fileExists('remote');
    }

    protected function login(): self
    {
        if (!$this->connection) {
            $this->connect();
        }

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

                if (!$this->login) {
                    throw new \Exception('Could not initialize SFTP subsystem.');
                }

                $this->setRemoteDirectory();

                break;
        }

        return $this;
    }

    public function uploadFile(): self
    {
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

    protected function readLocalDirectory(): array
    {
        return $this->readDirectory($this->localDirectory);
    }

    protected function readFile($source): string
    {
        return file_get_contents($this->getFilePath($source));
    }

    protected function readLocalFile(): string
    {
        return $this->readFile('local');
    }

    public function readRemoteDirectory(): array
    {
        return $this->readDirectory($this->remoteDirectory);
    }

    protected function readRemoteFile(): string
    {
        return $this->readFile('remote');
    }

    public function setLocalDirectory($dir): self
    {
        $this->localDirectory = $this->localRootDirectory.$dir;

        return $this;
    }

    public function setLocalFile($file): self
    {
        $this->localFile = $file;

        return $this;
    }

    public function setLocalRootDirectory($dir): self
    {
        $this->localRootDirectory = $dir;

        return $this;
    }

    public function setRemoteDirectory(string $dir = null): self
    {
        $dir = trim($dir, '/').'/';

        switch ($this->protocol) {
            case 'SFTP':
                $this->remoteDirectory = 'ssh2.sftp://'.$this->login;

                if (!is_null($dir)) {
                    $this->remoteDirectory .= '/'.$dir;
                }
                break;
            
            default:
                $this->remoteDirectory = $dir;
                break;
        }

        return $this;
    }

    public function setRemoteFile($file): self
    {
        $this->remoteFile = $file;

        return $this;
    }

    protected function readDirectory(string $dir): array
    {       
        switch ($this->protocol) {
            case 'FTP':
                return ftp_nlist($this->connection, $dir);
                break;
            
            case 'SFTP':
                $files = [];

                if (file_exists($dir) AND is_dir($dir)) {
                    $currentDir = scandir($dir);

                    foreach ($currentDir AS $entry) {
                        if ($entry[0] === '.' || $entry[0] === '~') {
                            continue;
                        }

                        if (is_dir($dir.$entry)) {
                            $entry .= '/';

                            foreach ($this->readDirectory($dir.$entry) AS $subEntry) {
                                $files[] = $entry.$subEntry;
                            }

                            continue;
                        }

                        $files[] = $entry;
                    }
                }
                break;
        }

        return $files;
    }
}