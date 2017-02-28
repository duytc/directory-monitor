<?php

namespace Tagcade\Bundle\AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RemoveIncompatibleFilesCommand extends ContainerAwareCommand
{
    protected $archivedFiles;

    const QUESTION_CONFIRM_DELETE = 'Are you sure to delete %d incompatible files? (y/n)';
    const OPTION_DELETE = 'delete';

    protected function configure()
    {
        $this
            ->setName('tc:ur:remove-incompatible-files')
            ->setDescription('Scan for incompatible files in pre-configured directory')
            ->addOption(
                self::OPTION_DELETE,
                null,
                InputOption::VALUE_NONE,
                'If set, the task will remove incompatibility files immediately'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();

        $this->archivedFiles = $this->getFileFullPath($container->getParameter('processed_archived_files'));

        if (!is_dir($this->archivedFiles)) {
            if (!mkdir($this->archivedFiles)) {
                throw new \Exception(sprintf('Can not create archivedFiles directory %s', $this->archivedFiles));
            }
        }

        if (!is_writable($this->archivedFiles)) {
            throw new \Exception(sprintf('Archived path is not writable. The full path is %s', $this->archivedFiles));
        }

        $supportedExtensions = $container->getParameter('supported_extensions');
        if (!is_array($supportedExtensions)) {
            throw new \Exception('Invalid configuration of param supported_extensions');
        }

        $this->deleteEmptyFolders($this->archivedFiles);
        
        $incompatibleFiles = $this->getIncompatibleFiles($supportedExtensions);
        if (count($incompatibleFiles) == 0){
            $output->writeln('None incompatible file found. Complete directory process');
            return;
        }

        $listFile = '';
        $number = 1;
        foreach ($incompatibleFiles as $incompatibleFile){
            $listFile = $listFile.$number.'  '.$incompatibleFile.PHP_EOL;
            $number++;
        }
        $output->writeln('List incompatible files:');
        $output->writeln($listFile);

        $forceDelete = $input->getOption(self::OPTION_DELETE);
        if (!$forceDelete) {
            $question = new ConfirmationQuestion(
                sprintf(
                    '<question>'.self::QUESTION_CONFIRM_DELETE.'</question>',
                    count($incompatibleFiles)
                ),
                false
            );
            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                return;
            }
        }
        $this->deleteIncompatibleFiles($incompatibleFiles);
        $this->deleteEmptyFolders($this->archivedFiles);
        $output->writeln(sprintf("Delete %d files", count($incompatibleFiles)));
    }

    protected function getFileFullPath($filePath)
    {
        $symfonyAppDir = $this->getContainer()->getParameter('kernel.root_dir');
        $isRelativeToProjectRootDir = (strpos($filePath, './') === 0 || strpos($filePath, '/') !== 0);
        $dataPath = $isRelativeToProjectRootDir ? sprintf('%s/%s', rtrim($symfonyAppDir, '/app'), ltrim($filePath, './')) : $filePath;

        return $dataPath;
    }

    protected function getIncompatibleFiles($supportedExtensions = ['csv', 'xls', 'xlsx', 'json'])
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
                $fileList[] = $fileFullPath;
                continue;
            }
        }

        return $fileList;
    }

    protected function supportFile($fileFullPath, array $supportedExtensions = ['csv', 'xls', 'xlsx', 'json'])
    {
        if (empty($fileFullPath)) {
            return false;
        }

        foreach ($supportedExtensions as $ext) {
            if ($ext == pathinfo($fileFullPath, PATHINFO_EXTENSION)) {
                return true;
            }
        }

        return false;
    }

    /**
     * delete incompatible files
     *
     * @param array $fileList
     */
    private function deleteIncompatibleFiles(array $fileList)
    {
        foreach ($fileList as $filePath) {
            if (is_file($filePath))
            {
                unlink($filePath);
            }
        }
    }

    /**
     * delete empty folders
     *
     * @param $path
     * @return bool
     */
    private function deleteEmptyFolders($path)
    {
        $empty=true;
        foreach (glob($path.DIRECTORY_SEPARATOR."*") as $file)
        {
            $empty &= is_dir($file) && $this->deleteEmptyFolders($file);
        }
        return $empty && rmdir($path);
    }
}