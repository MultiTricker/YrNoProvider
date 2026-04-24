<?php

/**
 * Prezenter.
 * - Pokud zaznam pro dane parametry nenajde v kesi:
 *      stahne aktualni soubor,
 *      zparsuje ho,
 *      vysledek ulozi do kese.
 * - Pokud najde, pouzije nakesovany.
 */

declare(strict_types=1);

namespace App\Presenters;

use Nette;
use Nette\Utils\Json;
use \App\Services\Logger;

use Nette\Utils\Strings;


use \App\Services\SmartCache;
// je potreba kvuli konstantam
use Nette\Caching\Cache;

final class YrnoPresenter extends Nette\Application\UI\Presenter
{
    use Nette\SmartObject;
    
    /** @var \App\Services\Downloader */
    private $downloader;

    /** @var \App\Services\YrnoParser */
    private $parser;

    /** @var \App\Services\SmartCache */
    private $cache;

    public function __construct(\App\Services\Downloader $downloader, \App\Services\YrnoParser $parser, \App\Services\SmartCache $cache  )
    {
        $this->downloader = $downloader;
        $this->parser = $parser;
        $this->cache = $cache;
    }


    /**
     * odhackuj=1 -> odstrani diakritiku
     * days=1..9  -> pocet dni predpovedi v sections (1=dnes+zitra, 2=+pozitri, …)
     * hours=1..48 -> pocet hodinovych zaznamu
     */
    public function renderForecast( $lat, $lon, $alt, $odhackuj=false, $mode=0, $days=1, $hours=12 )
    {
        try {

            if( !is_numeric($lat) || !is_numeric($lon) || !is_numeric($alt) ) {
                throw new \Exception( 'Vsechny parametry lat,lon,alt musi byt cislo' );
            }
    
            // nesmi se pouzit vice nez 4 desetinna mista; 3 mista = +-50 metru; 2 mista = +-500 metru
            $lat = number_format( floatval($lat), 3, '.', '' );
            $lon = number_format( floatval($lon), 3, '.', '' );
            $alt = intval( $alt );
            $days  = max( 1, min( intval($days),  9  ) );
            $hours = max( 1, min( intval($hours), 48 ) );
            Logger::log( 'app', Logger::INFO ,  "start {$lat} {$lon} {$alt} odhackuj=" . ($odhackuj ? 'Y' : 'N') . " days={$days} hours={$hours}" );

            // tohle zavolame vzdy; zajisti nacteni souboru, pokud je potreba
            $data = $this->downloader->getData( $lat, $lon, $alt );

            $key = "o_{$lat}_{$lon}_{$alt}_{$mode}_d{$days}_h{$hours}_" . ($odhackuj ? 'Y' : 'N');
            $rc = $this->cache->get( $key );
            if( $rc==NULL ) {
                Logger::log( 'app', Logger::INFO ,  "  parse: {$key}" );
                $rc = $this->parser->parse( $data, $odhackuj, $mode, $days, $hours );
                $this->cache->put( $key, $rc,  [
                        Cache::EXPIRE => '9 minutes'
                    ]
                );
            } else {
                Logger::log( 'app', Logger::DEBUG ,  "  out cache hit" );
            }

            Logger::log( 'app', Logger::INFO ,  "OK" );            

            $response = $this->getHttpResponse();
            $response->setHeader('Cache-Control', 'no-cache');
            $response->setExpiration('1 sec'); 

            $this->sendJson($rc);

        } catch (\Nette\Application\AbortException $e ) {
            // normalni scenar pro sendJson()
            throw $e;

        } catch (\Exception $e) {
            Logger::log( 'app', Logger::ERROR,  "ERR: " . get_class($e) . ": " . $e->getMessage() );
            
            $httpResponse = $this->getHttpResponse();
            $httpResponse->setCode(Nette\Http\Response::S500_INTERNAL_SERVER_ERROR );
            $httpResponse->setContentType('text/plain', 'UTF-8');
            $response = new \Nette\Application\Responses\TextResponse("ERR {$e->getMessage()}");
            $this->sendResponse($response);
            $this->terminate();
        }
    }

}