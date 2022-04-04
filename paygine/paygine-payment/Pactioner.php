<?php
class Pactioner extends Actioner {
    private static $pluginName = 'paygine-payment';

    /**
     * Сохраняет  опции плагина
     * @return boolean
     */
    public function saveBaseOption(){
        USER::AccessOnly('1,4','exit()');
        $this->messageSucces = $this->lang['SAVE_BASE'];
        $this->messageError = $this->lang['NOT_SAVE_BASE'];
        unset($_SESSION['paygine-paymentAdmin']);
        unset($_SESSION['paygine-payment']);

        if(!empty($_POST['data'])) {
            MG::setOption(array('option' => self::$pluginName.'-option', 'value' => addslashes(serialize($_POST['data']))));
        }

        return true;
    }

    public function notification(){
        try {
            $xml = file_get_contents("php://input");
            if (!$xml)
                throw new Exception("Empty data");

            $xml = simplexml_load_string($xml);

            if (!$xml)
                throw new Exception("Non valid XML was received");

            $response = json_decode(json_encode($xml));
            if (!$response)
                throw new Exception("Non valid XML was received");

//
//            unset($tmp_response["signature"]);
//            $signature = base64_encode(md5(implode('', $tmp_response) . CSalePaySystemAction::GetParamValue("Password")));
//            if ($signature !== $response->signature)
//                throw new Exception("Invalid signature");



            if (($response->reason_code)) {
                $o_id = $response->reference;
                if ($response->reason_code == 1){

                    $result_order = array();
                    $dbRes = DB::query('
                SELECT *
                FROM `'.PREFIX.'order`
                WHERE `id`=\''.$o_id.'\'
            ');

                    $result_order = DB::fetchAssoc($dbRes);
                    if ($result_order["paided"] == 0 && $result_order["status_id"] != 2){
                        $sql = '
                    UPDATE `'.PREFIX.'order` 
                    SET `paided` = 1, `status_id` = 2
                    WHERE `id` = \''.$o_id.'\'';
                        DB::query($sql);
                        }
                } else {

                }
                echo("ok");
            }
        } catch (Exception $ex) {
            $this->log->write(($ex->getMessage()));
            echo($ex->getMessage());
        }
    }

//   http://moguta.khomutov86.beget.tech/ajaxrequest?mguniqueurl=action/notification&pluginHandler=paygine-payment

    public function getPayLink(){
        $p_id = $_POST['paymentId'];
        $mgBaseDir = $_POST['mgBaseDir'];

        $result_payment = array();
        $dbRes = DB::query('
            SELECT *
            FROM `'.PREFIX.'payment`
            WHERE `id` = \''.$p_id.'\'');
        $result_payment = DB::fetchArray($dbRes);

        $result_order = array();
        $dbRes = DB::query('
            SELECT *
            FROM `'.PREFIX.'order`
            WHERE `payment_id`=\''.$p_id.'\' 
			ORDER BY id DESC LIMIT 1
        ');

        $result_order = DB::fetchAssoc($dbRes);

        $paymentParamDecoded = json_decode($result_payment[3]);
        $o_id = $result_order['id'];
        if (isset($result_order['delivery_cost']) and $result_order['delivery_cost'] > 0){
            $summ = $result_order['summ'] + $result_order['delivery_cost'];
        }else{
            $summ = $result_order['summ'];
        }
        if (strpos($summ, ".") !== false){
            $new_summ = str_replace(".", "", $summ);
            $summ = $new_summ;
        } else {
            $summ = $summ.'00';
        }
        $email = $result_order['user_email'];

        $desc = 'Оплата заказа № '.$o_id;

        $auth_key_array = array("", "");

        if (MG::getSetting('currencyShopIso') == 'RUR') {
            $curr = '643';
        }
        else{
            $curr = MG::getSetting('currencyShopIso');
        }

        foreach ($paymentParamDecoded as $key => $value) {
            if ($key == 'Тестовый режим') {
                $test = CRYPT::mgDecrypt($value);
            } elseif ($key == "Сектор") {
                $sector = CRYPT::mgDecrypt($value);
            } elseif ($key == "Пароль") {
                $password = CRYPT::mgDecrypt($value);
            } elseif ($key == "Передавать данные на свою ККТ") {
                $kkt= CRYPT::mgDecrypt($value);
            } elseif ($key == "Код ставки НДС для ККТ") {
                $nds= CRYPT::mgDecrypt($value);
            }
        }


        $url = 'http://'.$_SERVER['HTTP_HOST'];



        $urlDecoded = json_decode($result_payment[4]);
        foreach ($urlDecoded as $key => $value) {
            if ($key == "result URL:"){
                $resultURL = $url.$value;
            }
        }

        $signature  = base64_encode(md5($sector . intval($summ ) . $curr . $password));


        if ($test === 'true'){
            $paygine_url = "https://test.paygine.com";
        } else {
            $paygine_url = "https://pay.paygine.com";
        }

        $products = unserialize(stripslashes($result_order['order_content']));
        MG::loger($products);

        if ($kkt==1){
            $TAX = (strlen($nds) > 0) ?
                intval($nds) : 7;
            if ($TAX > 0 && $TAX < 7){
               //пробегаемся по товарам
                foreach ($products as $product) {
                    $fiscalPositions.=$product['count'].';';
                    $elementPrice = $product['price'];
                    $elementPrice = $elementPrice * 100;
                    $fiscalPositions.=$elementPrice.';';
                    $fiscalPositions.=$TAX.';';
                    $fiscalPositions.=$product['name'].'|';
                    $fiscalAmount = $fiscalAmount + (intval($product['quantity'])*intval($elementPrice));
                }
                //добавляем доставку
                if (isset($result_order['delivery_cost']) and $result_order['delivery_cost'] > 0){
                    $fiscalPositions.='1;';
                    $elementPrice = $result_order['delivery_cost'];
                    $elementPrice = $elementPrice * 100;
                    $fiscalPositions.=$elementPrice.';';
                    $fiscalPositions.=$TAX.';';
                    $fiscalPositions.='Доставка'.'|';

                    $fiscalAmount = $fiscalAmount + (intval($product['quantity'])*intval($elementPrice));
                }
                //добавляем скидку
                $amountDiff=abs($fiscalAmount - intval($amount * 100));
                if ($amountDiff!=0){
                    $fiscalPositions.='1'.';';
                    $fiscalPositions.=$amountDiff.';';
                    $fiscalPositions.=$TAX.';';
                    $fiscalPositions.='coupon'.';';
                    $fiscalPositions.='14'.'|';
                }
                $fiscalPositions = substr($fiscalPositions, 0, -1);
            }
        }


        if( $curl = curl_init() ) {
            curl_init();
            curl_setopt($curl, CURLOPT_URL, $paygine_url . '/webapi/Register');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER,true);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, 'sector='.$sector.'&reference='.$o_id.'&amount='.
                intval($summ).'&description='.$desc.'&email='.$email.'&fiscal_positions='.$fiscalPositions.'&currency='.$curr.'&mode='.'1'.'&signature='.$signature.'&url='.$resultURL);
            $paygine_order_id = curl_exec($curl);
            curl_close($curl);
        }

        if (intval($paygine_order_id) == 0) {
            error_log($paygine_order_id);
            return false;
        } else {
            $paygine_url = $paygine_url.'/webapi/Purchase';
            $signature = base64_encode(md5($sector . $paygine_order_id . $password));
            $this->data["result"] = "{$paygine_url}?sector={$sector}&id={$paygine_order_id}&signature={$signature}";
        }
        return true;
    }
    public function getSignature($data, $password, $encoded = true)
    {
        $data = array_filter($data, function ($var) {
            return $var !== '' && $var !== null;
        });
        ksort($data);
        $str = $password;
        foreach ($data as $k => $v) {
            $str .= '|' . $v;
        }
        if ($encoded) {
            return sha1($str);
        } else {
            return $str;
        }
    }
    public function isPaymentValid($paygineSettings, $response){
        if ($paygineSettings['merchant_id'] != $response['merchant_id']) {
            return false;
        }
        if ($response['order_status'] != 'approved') {
            return false;
        }
        $responseSignature = $response['signature'];
        if (isset($response['response_signature_string'])){
            unset($response['response_signature_string']);
        }
        if (isset($response['signature'])){
            unset($response['signature']);
        }
        if ($this->getSignature($response, $paygineSettings['secret_key']) != $responseSignature) {
            return false;
        }
        return true;
    }
}
