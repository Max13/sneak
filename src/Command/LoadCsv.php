<?php
/**
 * Created by PhpStorm.
 * User: etouraille
 * Date: 31/10/19
 * Time: 10:35
 */

namespace App\Command;

use App\Entity\Mapping;
use App\Metier\DifftoPrice;
use App\Metier\MappingToDiff;
use App\Metier\Report\Maker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LoadCsv extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'load:csv';
    private $em;


    public function __construct( EntityManagerInterface $em ) {
        $this->em = $em;
        $this->report = new Maker();
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $handle = fopen(__DIR__.'/urls.csv' , 'r+');
        while( $row = fgetcsv( $handle ) ) {
            $mapping = new Mapping();
            $mapping->setShopifyUrl($row[0]);
            $mapping->setStockxUrl( $row[1]);
            $mapping->setHashOldPriceAndSize('');
            $this->em->persist($mapping );
            $this->em->flush();

        }
        fclose( $handle );

    }
}
