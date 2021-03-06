<?php
/* WHMCS SMS Addon with GNU/GPL Licence
 * AktuelHost - http://www.aktuelhost.com
 *
 * https://github.com/AktuelSistem/WHMCS-SmsModule
 *
 * Developed at Aktuel Sistem ve Bilgi Teknolojileri (www.aktuelsistem.com)
 * Licence: GPLv3 (http://www.gnu.org/licenses/gpl-3.0.txt)
 * */

class SendGsm{
    var $sender;

    var $params;
    var $gsmnumber;
    var $message;
    var $userid;
    var $errors = array();
    var $logs = array();

    function getParams(){
        $params = json_decode($this->params);
        $this->addLog("SenderId: ".$params->senderid);
        return $params;
    }

    function send(){
        $this->gsmnumber = $this->util_gsmnumber($this->gsmnumber,$this->sender);
        $this->message = $this->util_convert($this->message);

        $sender_function = "Send" . $this->sender;

        $this->addLog("TO: ".$this->gsmnumber);
        $this->addLog("Message: ".$this->message);
        $this->addLog("Sender: ".$sender_function);

        $this->$sender_function();
    }

    function SendClickAtell(){
        $params = $this->getParams();

        $baseurl = "http://api.clickatell.com";

        $text = urlencode($this->message);
        $to = $this->gsmnumber;

        $url = "$baseurl/http/auth?user=$params->user&password=$params->pass&api_id=$params->apiid&from=$params->senderid";
        $ret = file($url);
        $this->addLog("Result from server: ".$ret);

        $sess = explode(":", $ret[0]);
        if ($sess[0] == "OK") {

            $sess_id = trim($sess[1]); // remove any whitespace
            $url = "$baseurl/http/sendmsg?session_id=$sess_id&to=$to&text=$text&from=$params->senderid";

            $ret = file($url);
            $send = explode(":", $ret[0]);

            if ($send[0] == "ID") {
                $this->addLog("Message sent.");
                $this->saveToDb($send[1]);
            } else {
                $this->addLog("Message sent failed. Error: $ret");
                $this->addError("Send message failed. Error: $ret");
            }
        } else {
            $this->addLog("Message sent failed. Authentication Error: $ret[0]");
            $this->addError("Authentication failed. $ret[0] ");
        }

    }

    function SendIletiMerkezi() {
        $params = $this->getParams();

        $url = "http://api.iletimerkezi.com/v1/send-sms/get/?username=$params->user&password=$params->pass&receipents=$this->gsmnumber&text=".urlencode($this->message)."&sender=".urlencode($params->senderid);

        $result = file_get_contents($url);
        $return = $result;
        $this->addLog("Result from server: ".$result);

        if(preg_match('/<status>(.*?)<code>(.*?)<\/code>(.*?)<message>(.*?)<\/message>(.*?)<\/status>(.*?)<order>(.*?)<id>(.*?)<\/id>(.*?)<\/order>/si', $result, $result_matches)) {
            $status_code = $result_matches[2];
            $status_message = $result_matches[4];
            $order_id = $result_matches[8];

            if($status_code == '200') {
                $this->addLog("Message sent.");
                $this->saveToDb($order_id);
            } else {
                $this->addLog("Message sent failed. Error: $status_message");
                $this->addError("Send message failed. Error: $status_message");
            }
        } else {
            $this->addLog("Message sent failed. Error: $return");
            $this->addError("Send message failed. Error: $return");
        }
    }

    function SendNetGsm(){
        $params = $this->getParams();

        $url = "http://api.netgsm.com.tr/bulkhttppost.asp?usercode=$params->user&password=$params->pass&gsmno=$this->gsmnumber&message=".urlencode($this->message)."&msgheader=$params->senderid";
        $result = file_get_contents($url);
        $return = $result;
        $this->addLog("Result from server: ".$result);

        $result = explode(" ", $result);
        if ($result[0] == "00" || $result[0] == "01" || $result[0] == "02") {
            $this->addLog("Message sent.");
            $this->saveToDb($result[1]);
        }else{
            $this->addLog("Message sent failed. Error: $return");
            $this->addError("Send message failed. Error: $return");
        }

    }

    function SendUcuzSmsAl(){
        $params = json_decode($this->params);

        $url = "http://www.ucuzsmsal.com/api/index.php?act=sendsms&user=".$params->user."&pass=".$params->pass."&orgin=".$params->senderid."&message=".urlencode($this->message)."&numbers=$this->gsmnumber";

        $result = file_get_contents($url);
        $return = $result;
        $this->addLog("Result from server: ".$result);

        $result = explode("|",$result);
        if($result[0]=="OK"){
            $this->addLog("Message sent.");
            $this->saveToDb($result[1]);
        }else{
            $this->addLog("Message sent failed. Error: $return");
            $this->addError("Send message failed. Error: $return");
        }

    }

    function saveToDb($msgid){
        $now = date("Y-m-d H:i:s");
        $table = "mod_aktuelsms_messages";
        $values = array("sender" => $this->sender, "to" => $this->gsmnumber, "text" => $this->message, "msgid" => $msgid, "status" => '', "user" => $this->userid, "datetime" => $now);
        insert_query($table, $values);

        $this->addLog("Message saved to db");
    }

    /* Here you can change anything from your message string */
    function util_convert($message){
        /* In this function i have changed Turkish characters to
        English chars.
        */
        $changefrom = array('ı', 'İ', 'ü', 'Ü', 'ö', 'Ö', 'ğ', 'Ğ', 'ç', 'Ç');
        $changeto = array('i', 'I', 'u', 'U', 'o', 'O', 'g', 'G', 'c', 'C');
        return str_replace($changefrom, $changeto, $message);
    }

    /* Here you can specify gsm numbers to your country */
    function util_gsmnumber($number,$sender){
        /* In this function i have removed special chars and
         * controlled number if it is real?
         * All numbers in Turkey starts with 0905 */
        $replacefrom = array('-', '(',')', '.', '+', ' ');
        $number = str_replace($replacefrom, '', $number);
        if (strlen($number) < 10) {
            $this->addLog("Number format is not correct: ".$number);
            $this->addError("Number format is not correct: ".$number);
            return null;
        }

        if($sender == "ClickAtell"){

        }elseif($sender == "UcuzSmsAl"){

            if (strlen($number) == 10) {

            } elseif (strlen($number) == 11) {
                $number = substr($number,1,strlen($number));
            } elseif (strlen($number) == 12) {
                $number = substr($number,2,strlen($number));
            }

            if (substr($number, 0, 1) != "5") {
                $this->addLog("Number format is not correct: ".$number);
                $this->addError("Number format is not correct: ".$number);
                return null;
            }
        }elseif($sender == "NetGsm"){
            if (strlen($number) == 10) {
                $number = '90' . $number;
            } elseif (strlen($number) == 11) {
                $number = '9' . $number;
            }

            if (substr($number, 0, 3) != "905") {
                $this->addLog("Number format is not correct: ".$number);
                $this->addError("Number format is not correct: ".$number);
                return null;
            }
        }

        return $number;
    }

    function addError($error){
        $this->errors[] = $error;
    }

    function addLog($log){
        $this->logs[] = $log;
    }

}