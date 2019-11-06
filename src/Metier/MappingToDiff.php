<?php
/**
 * Created by PhpStorm.
 * User: etouraille
 * Date: 31/10/19
 * Time: 18:08
 */

namespace App\Metier;


use App\Entity\Mapping;
use App\Entity\Redo;
use App\Metier\Proxy\FreshFactory;
use App\Metier\Report\Maker;
use App\Repository\MappingRepository;
use App\Utils\Counter;
use App\Utils\Dollard;
use Doctrine\ORM\EntityManagerInterface;
use Goutte\Client;


class MappingToDiff implements \Iterator
{

    private $em;
    private $report;
    private $proxyFactory;
    private $current;
    private $mappings = [];
    private $redo = null;
    private $counter;

    public function __construct(EntityManagerInterface $em, Maker $report , $redo = null ) {
        $this->em = $em;
        $this->report = $report;
        $this->proxyFactory = new FreshFactory( $em );
        $this->current = 0;
        $this->counter = new Counter();
        if( isset( $redo ) && is_string( $redo ) ) {
            $redos = $em->getRepository(Redo::class)->findPending($redo);
            // cas ou les redos ne sont pas necessaire.
            $n = $em->getRepository(Mapping::class)->count();
            if(  abs(count($redos) - (int) $n ) /  $n   > 0.97 ) {
                $redos = [];
                $this->report->addLine("Le nombre de produit en echec est inférieur à 5%, on les laisse en pending");
            }
            foreach ( $redos as $redo ) {
                $this->mappings[] = $em->getRepository(Mapping::class)->find( $redo->getMappingId());
            }
        } else {
            $this->mappings = $this->em->getRepository(Mapping::class)->findAll();
        }

    }


    public function  valid(){
        return isset($this->mappings[$this->current]);
    }

    public function rewind()
    {
        $this->current = 0;
    }

    public function next()
    {
        ++ $this->current;
    }

    public function key()
    {
        return $this->current;
    }

    public function current()
    {
        $this->report->addLine('.');
        $ret = null;
        $parser = new ProductParser($this->report );
        $mapping = $this->mappings[$this->current];
        $sizePrice = $this->getSizesAndPrices( $mapping->getStockxUrl() );
        $handle = $this->getHandle( $mapping->getShopifyUrl() );
        $productAndVariant = $parser->getProductAndVariant( $handle );
        if(0 === count($productAndVariant['variants'])) $this->report->addLine(sprintf('Aucun variant pour le produit %s', $mapping->getStockxyUrl()));
        return ['sizePrice' => $sizePrice, 'productAndVariant' => $productAndVariant , 'idMatch' => $mapping->getId()];

    }

    public function one( $stockxUrl ) {
        $match = $this->em->getRepository(Mapping::class )->findOneByStockxUrl( $stockxUrl );
        $ret = null;
        echo '.';
        if( $match ) {
            echo '.';
            $parser = new ProductParser($this->report );
            $sizePrice = $this->getSizesAndPrices( $match->getStockxUrl() );
            $handle = $this->getHandle( $match->getShopifyUrl() );
            $productAndVariant = $parser->getProductAndVariant( $handle );
            if(0 === count($productAndVariant['variants'])) $this->report->addLine(sprintf('Aucun variant pour le produit %s', $match->getShopifyUrl()));
            $ret = ['sizePrice' => $sizePrice, 'productAndVariant' => $productAndVariant , 'idMatch' => $match->getId()];


        }
        return $ret;
    }

    private function getHandle( $url ) {
        if(!preg_match("/\/([^(\/)]*)$/", $url, $match)) {
            $this->report->addLine(sprintf("Impossible d'extraire le handle de l'url %s", $url));
        }
        return isset($match[1])?$match[1]:false;
    }

    private function getSizesAndPrices( $url ) {

        $client = new Client();
        $guzzleClient = new \GuzzleHttp\Client([
            'proxy' => sprintf('http://%s:@proxy.crawlera.com:8010', $_ENV['CRAWLERA']),
            'defaults' => [
                'verify' => false
            ],
            'curl' => [
                CURLOPT_CAINFO => __DIR__.'/../../config/crawlera-ca.crt',
                CURLOPT_SSL_VERIFYPEER => TRUE
            ]
        ]);
        $client->setClient($guzzleClient);
        $crawler = $client->request('GET', $url);
        $this->counter->increment();
        $sizePrice = [];
        $crawler->filter('#market-summary > div.options > div > div > div.select-options > div:nth-child(2) > ul > li.select-option > div')->each(function( $node) use ( &$sizePrice , $url ) {
            if(!preg_match('/([^ ]*)\$(.*)$/',$node->text(), $match )) {
                if(!preg_match('/Bid/', $node->text())) $this->report->addLine(sprintf ("Problème lors de la reconnaissance des prix pour %s sur la chaine %s", $url , $node->text()));
            } else {
                $sizePrice[] = ['size' => $match[1], 'price' => Dollard::removeComa($match[2])];

            }
        });
        if(count($sizePrice) === 0 ) $this->report->addLine(sprintf('Problème lors du parsage des prix de Stockx pour %s', $url ));
        return $sizePrice;
    }
}
