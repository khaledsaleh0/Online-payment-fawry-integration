<?php

namespace App\Fawry;

use App\Models\Package;
use Illuminate\Support\Facades\App;

use App\Models\Order;
use Illuminate\Http\Request;
use DB;
use Illuminate\Support\Facades\Log;

class Fawry
{
    protected $fawryUrl;
    protected $merchant_code;
    protected $lang;
    public $package;
    protected $signature;
    public $merchant_ref;
    protected $product_sku;
    protected $returned_merchant_ref;
    public $payment_ref;
    public $amount;
    protected $fawryStatusUrl;

    public function __construct()
    {
        // Arabic Language
        $this->lang = "ar-eg";

        if (App::environment() == 'production') {
            $this->fawryUrl = 'https://www.atfawry.com/ECommercePlugin/FawryPay.jsp?chargeRequest';
            $this->fawryStatusUrl = 'https://atfawry.com/ECommerceWeb/Fawry/payments/status';
            $this->merchant_code = ''; //  insert merchant code here
            $this->sec_key = ''; // insert secret key here
        } else {
            $this->fawryUrl = 'https://atfawry.fawrystaging.com/ECommercePlugin/FawryPay.jsp?chargeRequest';
            $this->fawryStatusUrl = 'https://atfawry.fawrystaging.com/ECommerceWeb/Fawry/payments/status';
            $this->merchant_code = ''; //  insert sandbox merchant code here
            $this->sec_key = ''; // insert sandbox secret key here
        }
    }
    public function generateSignature() //Generate Fawry signature
    {
        $this->merchant_ref = "mecano-".$this->package->id.'-'.$this->package->type.'-' . rand(1000, 1000000);
        $this->product_sku = rand(1000, 1000000);
        $quantity = "1";
        $signature = $this->merchant_code . $this->merchant_ref . currentUser()->id . $this->product_sku . $quantity . number_format($this->package->price, 2) . $this->sec_key;
        $this->signature = hash('sha256', $signature);
    }
    public function build_request()  // build Fawry request to pay
    {
        $this->generateSignature();
        $str1 = '' . $this->fawryUrl . '={"merchantCode":"' . $this->merchant_code . '","language":"' . $this->lang . '","merchantRefNumber":"' . $this->merchant_ref . '","customer":{"name":"' . currentUser()->name . '","mobile":"' . currentUser()->phone . '","email":"' . currentUser()->email . '","customerProfileId":"' . currentUser()->id . '"},"order":{"expiry":"24","orderItems":[';
        $str2 = '';
        $str2 = $str2 . '{"productSKU":"' . $this->product_sku . '","description":"' . $this->package->name . '","price":"' . $this->package->price . '","quantity":"1","weight":"0.50"}';
        $str3 = ']},"signature":"' . $this->signature . '"}&successPageUrl=' . url('order/status') . '&failerPageUrl=' . url('order/fail');
        $str = $str1 . $str2 . $str3;

        return $str;
    }
    public function build_response($response_status, $amount, $FawryRefNo, $MerchantRefNo, $OrderStatus) // build Fawry response
    {
        $this->set_merchant_ref($MerchantRefNo);
        $response = $response_status . '<br>' . $OrderStatus . '<br>' . $FawryRefNo . '<br>' . $amount .  '<br>' . $this->returned_merchant_ref . '<br>' . date("Y-m-d h:i:sa");
        return $response;
    }
    public function fawry_fail(Request $request)
    {
        $input = $request->all();
        $aa = $input['merchantRefNum'];
        DB::table('orders')->where('merchant_ref_number', $aa)->update(['payment_status' => 'fail']);
    }
    public function validateCallback()
    {
        Log::channel('fawry')->info($_GET);
        $buffer = $this->sec_key . $_GET["Amount"] . $_GET["FawryRefNo"] . $_GET["MerchantRefNo"] . $_GET["OrderStatus"];
    
        $md5 = md5($buffer);
        $final =  strtoupper($md5);
        return $final == $_GET["MessageSignature"];
    }
    public function checkOrderStatus(Order $order)
    {
        $signature = hash('sha256', $this->merchant_code . $order->merchant_ref_number	 . $this->sec_key);
        $httpClient = new \GuzzleHttp\Client(); // guzzle 6.3
        $response = $httpClient->request('GET', $this->fawryStatusUrl, [
            'query' => [
                'merchantCode' => $this->merchant_code,
                'merchantRefNumber' => $order->merchant_ref_number,
                'signature' => $signature
            ]
        ]);
        $response = json_decode($response->getBody()->getContents(), true);
        return $response['payment_status'];
    }
    protected function set_merchant_ref($MerchantRefNo)
    {
        $this->returned_merchant_ref = $MerchantRefNo;
    }
    public function get_merchant_ref($MerchantRefNo)
    {
        return $this->returned_merchant_ref;
    }
    public function set_payment_ref($FawryRefNo)
    {
        $this->payment_ref = $FawryRefNo;
    }
    public function setPackage(Package $package)
    {
        $this->package = $package;
        $this->amount = $this->getAmount();
    }
    public function getAmount()
    {
        return $this->package->price;
    }
      
    // public function create_subscription()
    // {
    //     $order = Order::where('merchant_ref_number', $this->returned_merchant_ref)
    //                     ->where('status', 'success')->first();
    //     app(\App\Http\Controllers\SubscriptionController::class)->create_subscription($order);
    // }

}
