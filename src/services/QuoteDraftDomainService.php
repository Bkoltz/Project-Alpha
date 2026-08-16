<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

/** Shared exact-decimal policy for portal previews and private quote drafts. */
final class QuoteDraftDomainService
{
    /** @param list<array<string,mixed>> $services @return array{available:bool,total:string,currency:?string,lines:list<array<string,mixed>>,reason:?string} */
    public function priceServices(array $services): array
    {
        if ($services === []) throw new DomainException('catalog-services-required');
        $totalCents=0;$currency=null;$lines=[];$allFixed=true;
        foreach($services as $service){
            $serviceCurrency=strtoupper((string)($service['pricing_currency']??'USD'));
            if(preg_match('/^[A-Z]{3}$/D',$serviceCurrency)!==1)throw new DomainException('catalog-currency-invalid');
            if($currency!==null&&$currency!==$serviceCurrency)throw new DomainException('mixed-currency-policy');
            $currency=$serviceCurrency;
            $fixed=(string)($service['client_pricing_model']??'fixed')==='fixed'&&in_array((string)($service['billing_unit']??'each'),['each','project'],true);
            $cents=$fixed?self::moneyToMinor((string)$service['unit_price']):0;
            $allFixed=$allFixed&&$fixed;$totalCents+=$cents;
            $lines[]=['item_library_id'=>(int)$service['id'],'item'=>(string)$service['item_name'],'description'=>$service['description'],'quantity'=>'1.00','unit_price'=>self::minorToMoney($cents),'line_total'=>self::minorToMoney($cents),'billing_unit'=>(string)$service['billing_unit'],'pricing_status'=>$fixed?'standard':'variable','catalog_snapshot'=>['publicId'=>(string)$service['portal_public_id'],'sourceVersion'=>(string)$service['portal_source_version']]];
        }
        return ['available'=>$allFixed&&$totalCents>0,'total'=>self::minorToMoney($totalCents),'currency'=>$currency,'lines'=>$lines,'reason'=>$allFixed?($totalCents>0?null:'Pricing provided after review'):'Pricing depends on staff-reviewed service details'];
    }

    public static function moneyToMinor(string $amount): int
    {
        $amount=trim($amount);
        if(preg_match('/^(0|[1-9][0-9]{0,13})(?:\.([0-9]{1,2}))?$/D',$amount,$m)!==1)throw new DomainException('catalog-money-invalid');
        return ((int)$m[1])*100+(int)str_pad($m[2]??'',2,'0');
    }
    public static function minorToMoney(int $minor):string{if($minor<0)throw new DomainException('catalog-money-invalid');return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT);}
}
