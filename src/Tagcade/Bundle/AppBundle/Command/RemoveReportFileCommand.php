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
            ->setName('tc:unified-report:directory-monitor:remove-imported-files')
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
    }
} 