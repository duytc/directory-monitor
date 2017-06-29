<?php
namespace Tagcade\Bundle\AppBundle\Command;

use FilesystemIterator;
use Psr\Log\LoggerInterface;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RemoveIncompatibleFilesCommand extends ContainerAwareCommand
{
    const DEFAULT_SUPPORTED_EXTENSIONS = ImporterNewFilesCommand::DEFAULT_SUPPORTED_EXTENSIONS;
    const EXTENSION_META = 'meta';

    protected $archivedFiles;
    /** @var LoggerInterface */
    protected $logger;

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setName('tc:ur:remove-incompatible-files')
            ->setDescription('Scan for incompatible files in pre-configured directory');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        $this->logger = $container->get('logger');
        /* check if has config processed_archived_files and create directory if not existed */
        $this->archivedFiles = $this->getFileFullPath($container->getParameter('watch_root'));
        if (!is_dir($this->archivedFiles)) {
            if (!mkdir($this->archivedFiles)) {
                throw new \Exception(sprintf('Can not create archivedFiles directory %s', $this->archivedFiles));
            }
        }
        if (!is_writable($this->archivedFiles)) {
            throw new \Exception(sprintf('Archived path is not writable. The full path is %s', $this->archivedFiles));
        }
        /* check if has config supported_extensions */
        $supportedExtensions = $container->getParameter('supported_extensions');
        if (!is_array($supportedExtensions)) {
            throw new \Exception('Invalid configuration of param supported_extensions');
        }

        $incompatibleFilesCount = $this->deleteIncompatibleFiles($supportedExtensions);

        $this->logger->info(sprintf('Delete %d incompatible files', $incompatibleFilesCount));
    }

    /**
     * @param string $filePath
     * @return string
     */
    protected function getFileFullPath($filePath)
    {
        $symfonyAppDir = $this->getContainer()->getParameter('kernel.root_dir');
        $isRelativeToProjectRootDir = (strpos($filePath, './') === 0 || strpos($filePath, '/') !== 0);
        $dataPath = $isRelativeToProjectRootDir ? sprintf('%s/%s', rtrim($symfonyAppDir, '/app'), ltrim($filePath, './')) : $filePath;
        return $dataPath;
    }

    /**
     * delete empty folders
     *
     * @param string $path
     * @param int $emptyFoldersCount
     * @return bool
     */
    private function deleteEmptyFolders($path, &$emptyFoldersCount = 0)
    {
        $empty = true;
        foreach (glob($path . DIRECTORY_SEPARATOR . "*") as $file) {
            $empty &= is_dir($file) && $this->deleteEmptyFolders($file, $emptyFoldersCount);
        }
        $emptyFoldersCount += ($empty ? 1 : 0);
        return $empty && rmdir($path);
    }

    /**
     * delete incompatible files
     *
     * @param array $supportedExtensions
     * @return int
     */
    private function deleteIncompatibleFiles(array $supportedExtensions = self::DEFAULT_SUPPORTED_EXTENSIONS)
    {
        $unSupportedFiles = $this->getUnsupportedFiles($supportedExtensions);
        $aloneMetaDataFiles = $this->getAloneMetaDataFiles();

        $incompatibleFiles = array_merge($unSupportedFiles, $aloneMetaDataFiles);

        if (count($incompatibleFiles) == 0) {
            $this->logger->info(sprintf('None incompatible file found. Complete directory process'));
            return 0;
        }
        $number = 1;
        foreach ($incompatibleFiles as $incompatibleFile) {
            $this->logger->debug(sprintf('Removing file %d: %s', $number, $incompatibleFile));
            $number++;
        }
        // do removing incompatible files
        foreach ($incompatibleFiles as $filePath) {
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }
        return count($incompatibleFiles);
    }

    /**
     * @param array $supportedExtensions
     * @return array
     */
    protected function getUnsupportedFiles(array $supportedExtensions = self::DEFAULT_SUPPORTED_EXTENSIONS)
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->archivedFiles, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $fileList = [];
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $fileFullPath = $file->getRealPath();
            if (!is_file($fileFullPath)) {
                continue;
            }
            if (!$this->supportFile($fileFullPath, $supportedExtensions)) {
                if (!empty($fileFullPath)) {
                    // make sure fileFullPath is not empty
                    $fileList[] = $fileFullPath;
                }
                continue;
            }
        }
        return $fileList;
    }

    /**
     * @return array
     */
    protected function getAloneMetaDataFiles()
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->archivedFiles, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $fileList = [];
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $fileFullPath = $file->getRealPath();
            if (!is_file($fileFullPath)) {
                continue;
            }

            $ext = pathinfo($fileFullPath, PATHINFO_EXTENSION);
            if ($ext != self::EXTENSION_META) {
                continue;
            }

            $parent = $file->getPath();

            /** Alone file mean parent folder have only 1 file */
            $fi = new FilesystemIterator($parent, FilesystemIterator::SKIP_DOTS);
            if (iterator_count($fi) > 1) {
                continue;
            }

            $fileList[] = $fileFullPath;
        }
        return $fileList;
    }

    /**
     * @param string $fileFullPath
     * @param array $supportedExtensions
     * @return bool
     */
    protected function supportFile($fileFullPath, array $supportedExtensions = self::DEFAULT_SUPPORTED_EXTENSIONS)
    {
        if (empty($fileFullPath)) {
            return false;
        }
        $ext = pathinfo($fileFullPath, PATHINFO_EXTENSION);
        if (!in_array($ext, $supportedExtensions)) {
            return false;
        }
        // special case: files may come from email-webhook that created metadata files for unsupported files
        // e.g: file=abc.jpg and metadata-file=abc.jpg.meta
        // so that we need know these metadata files and allow remove them
        if ($ext === self::EXTENSION_META) {
            $filenameWithoutExtension = pathinfo($fileFullPath, PATHINFO_FILENAME);
            // remove .meta from supported extension
            $supportedExtensionsWithoutMetaExtension = array_filter($supportedExtensions, function ($value) {
                return $value !== self::EXTENSION_META;
            });
            // try to get extension (fake) from filename
            // if filePath=abc.jpg.meta => filenameWithoutExtension=abc.jpg => fakeExt=jpg
            $fakeExt = pathinfo($filenameWithoutExtension, PATHINFO_EXTENSION);
            if (!empty($fakeExt) && !in_array($fakeExt, $supportedExtensionsWithoutMetaExtension)) {
                return false;
            }
        }
        return true;
    }
}