<?php

namespace Tagcade\Bundle\AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Tagcade\Repository\Core\ImportedFileRepositoryInterface;

class RemoveReportFileCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('tc:remove-imported-files')
            ->setDescription('Remove imported files and folder. This command should be run once per day or a period of days depending hard disk capacity')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /**
         * @var ImportedFileRepositoryInterface $importedFileRepository
         */
        $importedFileRepository = $this->getContainer()->get('tagcade_app.repository.imported_file');

        $allImportedFiles = $importedFileRepository->findAll();
        foreach ($allImportedFiles as $file) {
            if (!file_exists($file)) {
                continue;
            }

            exec(sprintf('rm -f %s', $file));
        }

        // Remove all empty folders
        $watchRoot = $this->getContainer()->getParameter('watch_root');
        if (!file_exists($watchRoot) || !is_dir($watchRoot)) {
            throw new \InvalidArgumentException(sprintf('The folder %s does not exist', $watchRoot));
        }

        $allPublisherDirs = [];
        foreach ($allImportedFiles as $fileFullPath) {
            $fileRelativePath =  trim(str_replace($watchRoot, '', $fileFullPath), '/');
            $dirs = explode('/', $fileRelativePath);
            if (count($dirs) < 3) {
                exec(sprintf('', $fileFullPath));
                continue;
            }

            $publisherRelativePath = implode('/', array_slice($dirs, 0, 2));
            $publisherFullPath = sprintf('%s/%s',$watchRoot, $publisherRelativePath);
            $md5 = hash('md5', $publisherFullPath);
            if (!array_key_exists($md5, $allPublisherDirs)) {
                $allPublisherDirs[$md5] = $publisherFullPath;
            }
        }

        foreach ($allPublisherDirs as $md5 => $pubDir) {
            exec(sprintf('find %s -depth -type d -exec rmdir {} +', $pubDir));
        }
    }
} 