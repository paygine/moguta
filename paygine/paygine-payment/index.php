<?php
/*
  Plugin Name: paygine-payment
  Description: Плагин для оплаты через Paygine Processing.
  Author: DM
  Version: 1.
 */

new PayginePayment;

class PayginePayment {
    private static $pluginName = ''; // название плагина (соответствует названию папки)
    private static $lang = array(); // массив с переводом плагина
    private static $path = '';
    private static $options = '';

    public function __construct() {
        mgActivateThisPlugin(__FILE__, array(__CLASS__, 'activate')); //Инициализация  метода выполняющегося при активации
        mgAddAction(__FILE__, array(__CLASS__, 'pageSettingsPlugin')); //Инициализация  метода выполняющегося при нажатии на кнопку настроект плагина
        mgAddShortcode('paygine-payment', array(__CLASS__, 'addPaymentForm'));

        self::$pluginName = PM::getFolderPlugin(__FILE__);
        self::$path = PLUGIN_DIR.self::$pluginName;
        self::$lang = PM::plugLocales(self::$pluginName);
        self::$options = unserialize(stripcslashes(MG::getSetting(self::$pluginName.'-option')));
        if(URL::isSection('order')){
            if ( $_GET['addOrderOk'] ) {
                $_POST = $_SESSION['post'];
                if ($_POST['payment']) {
                    mgAddMeta('
                            <p style="display:none">
                            <input type="submit" id="paygine-submit" value="Оплатить" />
                            </p>     
                <script type="text/javascript">
                    $(document).ready( function(){
                        if($("input[name=phone]").val() == "" || $("input[name=email]").val() == ""){
                            return false;
                        }        					
                        $("#paygine-submit").click( function(){
                            $.ajax({
                                type: "POST",
                                async: false,
                                url: mgBaseDir+"/ajaxrequest",
                                dataType: \'json\',
                                data:{
                                    mguniqueurl: "action/getPayLink",
                                    pluginHandler: "paygine-payment",
                                    paymentId: ' . $_POST['payment'] . ',
                                    mgBaseDir: mgBaseDir,
                                },
                                cache: false,
                                success: function(response){
                                    if(response.status!=\'error\'){
                                       console.log(response)
                                        if (response.data.result != null){
                                           window.location.href = response.data.result;
                                        }
                                    }
                                }
                            });
                        })
                    setTimeout(function() {$( "#paygine-submit" ).trigger( "click" )}, 100);
                    })
                   </script>');
                }
            }
        }
    }

    static function activate(){
       USER::AccessOnly('1,4','exit()');
        self::setDefultPluginOption();
    }


    static function addPaymentForm() {
        return "<div id='paygine-payment-container'></div>";
    }
      /**
       * Вывод страницы плагина в админке
       */
    static function pageSettingsPlugin() {
        USER::AccessOnly('1,4','exit()');
        unset($_SESSION['payment']);
        echo '
          <link rel="stylesheet" href="'.SITE.'/'.self::$path.'/css/style.css" type="text/css" />
          <script type="text/javascript">
            includeJS("'.SITE.'/'.self::$path.'/js/script.js");          
          </script> ';

        $lang = self::$lang;
        $pluginName = self::$pluginName;
        $options = self::$options;
        $data['propList'] = self::getPropList();

        // подключаем view для страницы плагина
        include 'pageplugin.php';
    }
	  private static function getPropList(){
        $arResult = array();
        $sql = '
            SELECT `id`, `name` 
            FROM `'.PREFIX.'property` 
            WHERE `activity` = 1 AND `type` = \'string\'';

        if($dbRes = DB::query($sql)){
            while($result = DB::fetchAssoc($dbRes)){
                $arResult[$result['id']] = $result['name'];
            }
        }

        return $arResult;
    }
    private static function setDefultPluginOption(){
        USER::AccessOnly('1,4','exit()');
        $paymentId = self::getPaymentForPlugin();

        if(MG::getSetting(self::$pluginName.'-option') == null || empty($paymentId)){
          if(empty($paymentId)){
            $paymentId = self::setPaymentForPlugin();
          }

          $arPluginParams = array(
            'payment_id' => $paymentId,
            'currency' => '',
          );

          MG::setOption(array('option' => self::$pluginName.'-option', 'value' => addslashes(serialize($arPluginParams))));
        }
      }



    /**
    * Возвращает идентификатор записи доставки из БД для плагина, по полю 'name'
    */
    static function getPaymentForPlugin(){
        $result = array();
        $dbRes = DB::query('
          SELECT id
          FROM `'.PREFIX.'payment`
          WHERE `name` = \'PaygineProcessing\'');

        if($result = DB::fetchAssoc($dbRes)){
          $sql = '
            UPDATE `'.PREFIX.'payment` 
            SET `activity` = 1 
            WHERE `name` = \'PaygineProcessing\'';
          DB::query($sql);

          return $result['id'];
        }
    }

    static function setPaymentForPlugin(){
        USER::AccessOnly('1,4','exit()');

        $sql = '
            INSERT INTO '.PREFIX.'payment (`name`, `activity`,`paramArray`, `urlArray`) VALUES
            (\'Paygine\', 1, \'{"Сектор":"", "Пароль":"", "Тестовый режим":"", "Передавать данные на свою ККТ":"", "Код ставки НДС для ККТ":""}\', \'{}\')';

        if(DB::query($sql)){

            $thisId = DB::insertId();
            $sql = '
                UPDATE `'.PREFIX.'payment` 
                SET `urlArray` = \'{"result URL:":"/payment?paymentid='.$thisId.'&pay=result","back URL:":"/ajaxrequest?mguniqueurl=action/notification&pluginHandler=paygine-payment"}\'
                WHERE `id` = \''.$thisId.'\'';

            DB::query($sql);

            return $thisId;
        }
    }



}

?>