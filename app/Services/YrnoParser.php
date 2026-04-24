<?php

/**
 * Projde stazene XML a sestavi z nej JSON data pro zadane parametry.
 */

declare(strict_types=1);

namespace App\Services;

use Nette;
use Nette\Utils\DateTime;
use Nette\Utils\Strings;
use Nette\Utils\Json;
use Tracy\Debugger;

use \App\Services\Logger;

class YrnoParser 
{
    use Nette\SmartObject;

	public function __construct(  )
	{
	}

    private $odhackuj = false;
    
    /**
     * Odstrani hacky a carky, pokud je to pozadovane.
     */
    private function textCnv( $text ) 
    {
        return !$this->odhackuj ? $text : iconv("utf-8", "us-ascii//TRANSLIT", $text );
    }

    private $dny = [ ' ', 'po', 'út', 'st', 'čt', 'pá', 'so', 'ne' ];

    // ASCII verze nazvu dnu - pouziva se pro nazvy sekcí v JSON výstupu
    private $dnyAscii = [ ' ', 'po', 'ut', 'st', 'ct', 'pa', 'so', 'ne' ];

    private function hezkeDatum( $date )
    {
        $today = new Nette\Utils\DateTime();
        $dateT = $date->format('Y-m-d');

        if( strcmp( $today->format('Y-m-d') , $dateT)==0 ) {
            return "dnes " . $date->format('H:i');
        }

        if( strcmp( $today->modifyClone('+1 day')->format('Y-m-d') , $dateT)==0 ) {
            return "zítra " . $date->format('H:i');
        }

        return $this->dny[$date->format('N')] . ' ' . $date->format( 'j.n. H:i' );
    }

        // https://api.met.no/weatherapi/weathericon/2.0/documentation
        // https://hjelp.yr.no/hc/en-us/articles/203786121-Weather-symbols-on-Yr
        // https://github.com/nrkno/yr-weather-symbols
        /*
prioritizace:

clearsky	1	Clear sky	
fair	2	Fair	            // polojasno
partlycloudy	3	Partly cloudy	
cloudy	4	Cloudy	

fog	15	Fog	

lightrainshowers	40	Light rain showers	
lightrain	46	Light rain	
lightrainshowersandthunder	24	Light rain showers and thunder	
lightrainandthunder	30	Light rain and thunder	

rainshowers	5	Rain showers	
rain	9	Rain	
rainshowersandthunder	6	Rain showers and thunder	
rainandthunder	22	Rain and thunder	

heavyrainshowers	41	Heavy rain showers	
heavyrain	10	Heavy rain	
heavyrainshowersandthunder	25	Heavy rain showers and thunder	
heavyrainandthunder	11	Heavy rain and thunder	

lightsleetshowers	42	Light sleet showers	
lightsleet	47	Light sleet	
lightssleetshowersandthunder	26	Light sleet showers and thunder	
lightsleetandthunder	31	Light sleet and thunder	

sleetshowers	7	Sleet showers	
sleet	12	Sleet	                // dest se snehem
sleetshowersandthunder	20	Sleet showers and thunder	
sleetandthunder	23	Sleet and thunder	

heavysleetshowers	43	Heavy sleet showers	
heavysleet	48	Heavy sleet	
heavysleetshowersandthunder	27	Heavy sleet showers and thunder	
heavysleetandthunder	32	Heavy sleet and thunder	

lightsnowshowers	44	Light snow showers	
lightsnow	49	Light snow	
lightssnowshowersandthunder	28	Light snow showers and thunder	
lightsnowandthunder	33	Light snow and thunder	

snowshowers	8	Snow showers	
snow	13	Snow	
snowshowersandthunder	21	Snow showers and thunder        
snowandthunder	14	Snow and thunder	

heavysnowshowers	45	Heavy snow showers	
heavysnow	50	Heavy snow	
heavysnowshowersandthunder	29	Heavy snow showers and thunder	
heavysnowandthunder	34	Heavy snow and thunder	
        */

    private $icons = array( 
        'clearsky',
        'fair',
        'partlycloudy',
        'cloudy',
        'fog',
        'lightrainshowers',
        'lightrain',
        'lightrainshowersandthunder',
        'lightrainandthunder',
        'rainshowers',
        'rain',
        'rainshowersandthunder',
        'rainandthunder',
        'heavyrainshowers',
        'heavyrain',
        'heavyrainshowersandthunder',
        'heavyrainandthunder',
        'lightsleetshowers',
        'lightsleet',
        'lightssleetshowersandthunder',
        'lightsleetandthunder',
        'sleetshowers',
        'sleet',
        'sleetshowersandthunder',
        'sleetandthunder',
        'heavysleetshowers',
        'heavysleet',
        'heavysleetshowersandthunder',
        'heavysleetandthunder',
        'lightsnowshowers',
        'lightsnow',
        'lightssnowshowersandthunder',
        'lightsnowandthunder',
        'snowshowers',
        'snow',
        'snowshowersandthunder',
        'snowandthunder',
        'heavysnowshowers',
        'heavysnow',
        'heavysnowshowersandthunder',
        'heavysnowandthunder' );

    private function najdiNejdulezitejsiIkonu( $symbols )
    {
        $rc = 'clearsky';
        $prevIndex = 0;

        foreach( array_keys($symbols) as $icon ) {
            // partlycloudy_day - oddelime vse za podtrzitkem
            $icon_split = explode ( '_' , $icon );
            $i = array_search ( $icon_split[0] , $this->icons );
            if( $i>$prevIndex ) {
                $rc = $this->icons[$i];
                $prevIndex = $i;
            }
        }

        return $rc;
    }        


    private $json;
    

    /**
     * Vrati nazev dne pro dany offset (0=dnes, 1=zitra, 2+=den v tydnu).
     */
    private function nazevDne( $offset )
    {
        if( $offset == 0 ) return 'dnes';
        if( $offset == 1 ) return 'zitra';
        $date = new Nette\Utils\DateTime();
        $date->modify( "+{$offset} days" );
        return $this->dnyAscii[ $date->format('N') ];
    }

    /**
     * $odOffset / $doOffset: celocíselny offset dne od dneska (0=dnes, 1=zitra, 2=pozitri, …)
     */
    private function najdiSerie( $odOffset, $odHod, $doOffset, $doHod, $nazev )
    {
        //D/ 
        Logger::log( 'app', Logger::DEBUG ,  "  {$nazev}: hledam od +{$odOffset}d {$odHod} do +{$doOffset}d {$doHod}" );

        $fromLimit = new Nette\Utils\DateTime();
        if( $odOffset > 0 ) {
            $fromLimit->modify( "+{$odOffset} days" );
        }
        $fromLimit->setTime( $odHod, 0, 0 );
        $fromT = $fromLimit->getTimestamp() - 10;

        $toLimit = new Nette\Utils\DateTime();
        if( $doOffset > 0 ) {
            $toLimit->modify( "+{$doOffset} days" );
        }
        $toLimit->setTime( $doHod, 0, 0 );
        $toT = $toLimit->getTimestamp() + 10;

        $minTemp = +100;
        $maxTemp = -100;
        $sumRain = 0;
        $maxHourRain = 0;
        $minCloud = 101;
        $maxCloud = -101;
        $minFog = 101;
        $maxFog = -101;
        $minWind = null;
        $maxWind = null;
        $minPressure = null;
        $maxPressure = null;
        $symbols = array();

        foreach( $this->json->properties->timeseries as $ts ) {
            $time = strtotime( $ts->time );
            $fromTime = DateTime::from( $time );
            $use = $time>$fromT && $time<$toT ;
            // Logger::log( 'app', Logger::DEBUG ,  "serie pro {$ts->time} - " . $fromTime . ' ' . ($use ? 'YES' : '' ) ); 
            if( $use ) {
                if( isset($ts->data->instant->details->air_temperature) ) {
                    $t = $ts->data->instant->details->air_temperature;
                    if( $t > $maxTemp ) { $maxTemp = $t; }
                    if( $t < $minTemp ) { $minTemp = $t; }
                }

                // yr.no: první ~48h má next_1_hours, pak jen next_6_hours
                $nextBlock = isset($ts->data->next_1_hours)
                    ? $ts->data->next_1_hours
                    : (isset($ts->data->next_6_hours) ? $ts->data->next_6_hours : null);

                if( $nextBlock !== null ) {
                    if( isset($nextBlock->details->precipitation_amount) ) {
                        $r = $nextBlock->details->precipitation_amount;
                        if( $r > $maxHourRain ) { $maxHourRain = $r; }
                        $sumRain += $r;
                    }
                    if( isset($nextBlock->summary->symbol_code) ) {
                        $s = $nextBlock->summary->symbol_code;
                        if( isset($symbols[$s]) ) {
                            $symbols[$s]++;
                        } else {
                            $symbols[$s] = 1;
                        }
                    }
                }

                if( isset($ts->data->instant->details->cloud_area_fraction) ) {
                    $c = $ts->data->instant->details->cloud_area_fraction;
                    if( $c > $maxCloud ) { $maxCloud = $c; }
                    if( $c < $minCloud ) { $minCloud = $c; }
                } else {
                    $c = '-';
                }
                if( isset($ts->data->instant->details->fog_area_fraction) ) {
                    $f = $ts->data->instant->details->fog_area_fraction;
                    if( $f > $maxFog ) { $maxFog = $f; }
                    if( $f < $minFog ) { $minFog = $f; }
                } else {
                    $f = '-';
                }

                if( isset($ts->data->instant->details->wind_speed) ) {
                    $w = $ts->data->instant->details->wind_speed;
                    if( $maxWind === null || $w > $maxWind ) { $maxWind = $w; }
                    if( $minWind === null || $w < $minWind ) { $minWind = $w; }
                }

                if( isset($ts->data->instant->details->air_pressure_at_sea_level) ) {
                    $p = $ts->data->instant->details->air_pressure_at_sea_level;
                    if( $maxPressure === null || $p > $maxPressure ) { $maxPressure = $p; }
                    if( $minPressure === null || $p < $minPressure ) { $minPressure = $p; }
                }

                //D/
                $t_log = isset($ts->data->instant->details->air_temperature) ? $ts->data->instant->details->air_temperature : '-';
                $c_log = isset($ts->data->instant->details->cloud_area_fraction) ? $ts->data->instant->details->cloud_area_fraction : '-';
                $r_log = ($nextBlock !== null && isset($nextBlock->details->precipitation_amount)) ? $nextBlock->details->precipitation_amount : '-';
                $s_log = ($nextBlock !== null && isset($nextBlock->summary->symbol_code)) ? $nextBlock->summary->symbol_code : '-';
                Logger::log( 'app', Logger::DEBUG , '    ' . $fromTime . " temp {$t_log}, rain {$r_log}, cloud {$c_log}, icon {$s_log}" );
            }
        }
        $info = array();
        $info['nazev'] = $nazev;
        $info['temp_min'] = ($minTemp != +100) ? $minTemp : '-';
        $info['temp_max'] = ($maxTemp != -100) ? $maxTemp : '-';
        $info['rain_sum'] = $sumRain;
        $info['rain_max'] = $maxHourRain;
        $info['clouds_min'] = ($minCloud != 101) ? $minCloud : '-';
        $info['clouds_max'] = ($maxCloud != -101) ? $maxCloud : '-';
        $info['fog'] = ($maxFog != -101) ? $maxFog : '-';
        $info['wind_speed_min'] = ($minWind !== null) ? $minWind : '-';
        $info['wind_speed_max'] = ($maxWind !== null) ? $maxWind : '-';
        $info['pressure_min'] = ($minPressure !== null) ? $minPressure : '-';
        $info['pressure_max'] = ($maxPressure !== null) ? $maxPressure : '-';

        // vytvoreni ikony - experimental, chybi snih a mlha
        /*
        if( $maxHourRain > 3 ) {
            $icon = 'heavyrain';
        } else if( $maxHourRain > 1 ) {
            $icon = 'rain';
        } else if( $maxHourRain > 0.1 ) {
            $icon = 'lightrain';
        } else if( $maxCloud < 30 ) {
            $icon = 'sun';
        } else if( $maxCloud < 70 ) {
            $icon = 'partlycloudy';
        } else {
            $icon = 'cloudy';
        }
        $info['icon-my'] = $icon;
        */

        $info['icon'] = $this->najdiNejdulezitejsiIkonu( $symbols ); 

        //D/ 
        Logger::log( 'app', Logger::INFO , "  {$nazev}: {$info['icon']}; temp {$minTemp}..{$maxTemp}; rain {$sumRain} tot, {$maxHourRain}/hr; clouds {$minCloud}..{$maxCloud}; fog {$minFog}..{$maxFog}" );

        return $info;
    }


    private function vytvorSekce( $days = 1 )
    {
        $rc = array();

        $curHour = intval( date( 'G' ) );
        if( $curHour <= 3 ) {
            // noc: 00-05 
            $rc[] = $this->najdiSerie( 0, 0, 0, 5, 'dnes_noc' );
            // dopoledne: 06-11
            $rc[] = $this->najdiSerie( 0, 6, 0, 11, 'dnes_dopoledne' );
            // odpoledne: 12-17
            $rc[] = $this->najdiSerie( 0, 12, 0, 17, 'dnes_odpoledne' );
            // vecer: 18-21
            $rc[] = $this->najdiSerie( 0, 18, 0, 21, 'dnes_vecer' );
            // noc: 22-05
            $rc[] = $this->najdiSerie( 0, 22, 1, 5, 'zitra_noc' );
            // zitra: +1d 6-20 min max dest
            $rc[] = $this->najdiSerie( 1, 6, 1, 20, 'zitra_den' );
        } else if( $curHour <= 11 ) {
            // dopoledne: 06-11 
            $rc[] = $this->najdiSerie( 0, 6, 0, 11, 'dnes_dopoledne' );
            // odpoledne: 12-17
            $rc[] = $this->najdiSerie( 0, 12, 0, 17, 'dnes_odpoledne' );
            // vecer: 18-21
            $rc[] = $this->najdiSerie( 0, 18, 0, 21, 'dnes_vecer' );
            // noc: 22-05
            $rc[] = $this->najdiSerie( 0, 22, 1, 5, 'dnes_noc' );
            // zitra: +1d 6-20 min max dest
            $rc[] = $this->najdiSerie( 1, 6, 1, 20, 'zitra_den' );
        } else if( $curHour <= 17 ) {
            // odpoledne: 12-17
            $rc[] = $this->najdiSerie( 0, 12, 0, 17, 'dnes_odpoledne' );
            // vecer: 18-21
            $rc[] = $this->najdiSerie( 0, 18, 0, 21, 'dnes_vecer' );
            // noc: 22-05
            $rc[] = $this->najdiSerie( 0, 22, 1, 5, 'dnes_noc' );
            // zitra dopoledne: +1d 06-11
            $rc[] = $this->najdiSerie( 1, 6, 1, 11, 'zitra_dopoledne' );
            // zitra odpoledne: +1d 12-17
            $rc[] = $this->najdiSerie( 1, 12, 1, 17, 'zitra_odpoledne' );
        } else if( $curHour <= 21 ) {
            // vecer: 18-21
            $rc[] = $this->najdiSerie( 0, 18, 0, 21, 'dnes_vecer' );
            // noc: 22-05
            $rc[] = $this->najdiSerie( 0, 22, 1, 5, 'dnes_noc' );
            // zitra dopoledne: +1d 06-11
            $rc[] = $this->najdiSerie( 1, 6, 1, 11, 'zitra_dopoledne' );
            // zitra odpoledne: +1d 12-17
            $rc[] = $this->najdiSerie( 1, 12, 1, 17, 'zitra_odpoledne' );
            // zitra vecer: +1d 18-21
            $rc[] = $this->najdiSerie( 1, 18, 1, 21, 'zitra_vecer' );
        } else {
            // noc: 22-05
            $rc[] = $this->najdiSerie( 0, 22, 1, 5, 'dnes_noc' );
            // zitra dopoledne: +1d 06-11
            $rc[] = $this->najdiSerie( 1, 6, 1, 11, 'zitra_dopoledne' );
            // zitra odpoledne: +1d 12-17
            $rc[] = $this->najdiSerie( 1, 12, 1, 17, 'zitra_odpoledne' );
            // zitra vecer: +1d 18-21
            $rc[] = $this->najdiSerie( 1, 18, 1, 21, 'zitra_vecer' );
        }

        // dalsi dny (2, 3, … az $days)
        for( $d = 2; $d <= $days; $d++ ) {
            $nazev = $this->nazevDne( $d ) . '_den';
            $rc[] = $this->najdiSerie( $d, 6, $d, 20, $nazev );
        }

        return $rc;
    }


    private function vytvorHodiny( $pocetHodin = 12 )
    {
        $rc = array();

        // omezeni: API yr.no ma hodinova data cca 48h, pak jen 6h intervaly
        $pocetHodin = min( $pocetHodin, 48 );

        $now = (new DateTime())->modify('-1 hour')->getTimestamp();
        foreach( $this->json->properties->timeseries as $ts ) {
            $time = strtotime( $ts->time );
            $fromTime = DateTime::from( $time );
            if( $time <= $now ) continue;

            // yr.no ma next_1_hours pouze pro cca prvnich 48h; pozdeji jen next_6_hours - preskocime
            if( !isset($ts->data->next_1_hours) ) continue;

            $t = isset($ts->data->instant->details->air_temperature)
                ? $ts->data->instant->details->air_temperature
                : '-';
            $r = isset($ts->data->next_1_hours->details->precipitation_amount)
                ? $ts->data->next_1_hours->details->precipitation_amount
                : '-';
            if( isset($ts->data->instant->details->cloud_area_fraction) ) {
                $c = $ts->data->instant->details->cloud_area_fraction;
            } else {
                $c = '-';
            }
            $s = isset($ts->data->next_1_hours->summary->symbol_code)
                ? $ts->data->next_1_hours->summary->symbol_code
                : 'clearsky';
            $icon_split = explode ( '_' , $s );

            if( isset($ts->data->instant->details->fog_area_fraction) ) {
                $f = $ts->data->instant->details->fog_area_fraction;
            } else {
                $f = '-';
            }

            $w = isset($ts->data->instant->details->wind_speed)
                ? $ts->data->instant->details->wind_speed
                : '-';

            $pressure = isset($ts->data->instant->details->air_pressure_at_sea_level)
                ? $ts->data->instant->details->air_pressure_at_sea_level
                : '-';

            //D/
            Logger::log( 'app', Logger::DEBUG , '  - ' . $fromTime . " temp {$t}, rain {$r}, cloud {$c}, icon {$s}" );

            $info = array();
            $info['hour'] = $fromTime->format('H');
            $info['temp'] = $t;
            $info['rain'] = $r;
            $info['clouds'] = $c;
            $info['fog'] = $f;
            $info['wind_speed'] = $w;
            $info['pressure'] = $pressure;
            $info['icon'] = $icon_split[0];
            $rc[] = $info;

            if( --$pocetHodin == 0 ) break;
        }

        return $rc;
    }


    public function parse( $data, $odhackuj, $mode, $days = 1, $hours = 12 )
    {
        // Debugger::enable();

        if( $odhackuj ) {
            $this->odhackuj = true; 
            // aby fungoval iconv
            setlocale(LC_ALL, 'czech'); // záleží na použitém systému
        } 

        // days: minimalne 1 (dnes+zitra), maximalne 9 (limit API yr.no)
        $days = max( 1, min( intval($days), 9 ) );
        // hours: minimalne 1, maximalne 48 (hodinova data yr.no)
        $hours = max( 1, min( intval($hours), 48 ) );

        $this->json = Json::decode($data);
        
        $rc = array();
        if( $mode==0 || $mode==1 ) {
            // sekce - dopoledne, odpoledne, vecer (+ dalsi dny dle $days)
            $rc['sections'] = $this->vytvorSekce( $days );
        }
        if( $mode==0 || $mode==2 ) {
            // hodinove predpovedi pro nejblizsich $hours hodin
            $rc['hours'] = $this->vytvorHodiny( $hours );
        }
        return $rc;
    }

}