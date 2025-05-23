<?php

namespace Paytic\Omnipay\PlatiOnline\Message\Traits;

use Paytic\Omnipay\PlatiOnline\Utils\Urls;
use Nip\Utility\Xml;
use phpseclib\Crypt\AES;
use phpseclib\Crypt\RSA;

/**
 * Trait HasSoapRequestTrait
 * @package Paytic\Omnipay\PlatiOnline\Message\Traits
 */
trait HasSoapRequestTrait
{
    use \Paytic\Omnipay\Common\Message\Traits\Soap\AbstractSoapRequestTrait;

    /**
     * @inheritDoc
     */
    public function getSoapEndpoint()
    {
        return null;
    }

    /**
     * @return string
     */
    protected function getSoapAction(): string
    {
        return 'auth-only';
    }

    protected function getSoapOptions(): array
    {
        $options = $this->getSoapOptionsGeneric();
        $options['location'] = Urls::$url;
        $options['uri'] = $this->getSoapAction();
        return $options;
    }

    abstract protected function getSoapRequestValidationUrl();
    abstract protected function getSoapResponseValidationUrl();

    /**
     * @param \SoapClient $soapClient
     * @param $data
     * @return \SimpleXMLElement
     * @throws \Exception
     */
    protected function runSoapRequest($soapClient, $data, $type)
    {
        $request = $this->setFRequest($data, $type, $this->getSoapRequestValidationUrl());
        $response = $soapClient->__doRequest($request, Urls::$url, $this->getSoapAction(), 1);
        if (empty($response)) {
            throw new \Exception('ERROR: Nu am putut comunica cu serverul PO pentru operatiunea de autorizare!');
        }
        
        // Check if response is XML format (starts with '<')
        if (substr(trim($response), 0, 1) !== '<') {
            // If not XML, throw an exception with the actual server response
            throw new \Exception('Server response is not in XML format: ' . $response);
        }
        
        try {
            Xml::validate($response, $this->getSoapResponseValidationUrl());
            $responseObject = simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA);
            return $responseObject;
        } catch (\Exception $e) {
            // If XML validation or parsing fails, throw an exception with details
            throw new \Exception('Failed to process XML response: ' . $e->getMessage() . '. Original response: ' . $response);
        }
    }

    /**
     * setez f_request, criptez f_request cu AES si cheia AES cu RSA
     * @param $message
     * @param $type
     * @param $validationUrl
     * @return mixed
     * @throws \Exception
     */
    protected function setFRequest($message, $type, $validationUrl)
    {
        // aici construiesc XML din array
        $xml = new \SimpleXMLElement('<' . $type . '/>');

        // test mode
        if (in_array($type, ['po_auth_request', 'po_payment_sale_by_token'])) {
            $message['f_test_request'] = ($this->getTestMode() == true) ? 1 : 0;
            $message['f_sequence'] = rand(1, 1000);
            $message['f_customer_ip'] = $this->getClientIp();
        }

        $message['f_timestamp'] = date('Y-m-d\TH:i:sP');

        // set f_login
        $message['f_login'] = $this->getLoginId();

        // sortez parametrii alfabetic
        ksort($message);

        $this->array2xml($message, $xml);
        $message = $xml->asXML();

        // validez XML conform schemei (parametrul 2)
        Xml::validate($message, $validationUrl);

        $request = [
            'po_request' => [
                'f_login' => $this->getLoginId(),
                'f_message' => $this->AESEnc($message),
                'f_crypt_message' => $this->RSAEnc()
            ]
        ];

        $xml_auth_soap = Xml::fromArray($request)->asXML();
        Xml::validate($xml_auth_soap, Urls::$requestXml);

        return $xml_auth_soap;
    }
    /**
     * function definition to convert array to xml
     * @param $arr
     * @param $xml_arr
     */
    protected function array2xml($arr, &$xml_arr)
    {
        foreach ($arr as $key => $value) {
            if (is_array($value)) {
                if (!is_numeric($key)) {
                    if (strpos($key, 'coupon') !== false) {
                        $subnode = $xml_arr->addChild("coupon");
                    } else {
                        $subnode = $xml_arr->addChild("$key");
                    }
                    $this->array2xml($value, $subnode);
                } else {
                    $subnode = $xml_arr->addChild("item");
                    $this->array2xml($value, $subnode);
                }
            } else {
                $xml_arr->addChild("$key", htmlspecialchars("$value"));
            }
        }
    }


    // criptez f_request cu AES
    protected function AESEnc($message)
    {
        $aes = new AES();
        $aes->setIV($this->getInitialVector());
        $aes->setKey($this->getAesKey());
        return bin2hex(base64_encode($aes->encrypt($message)));
    }

    // criptez cheia AES cu RSA
    protected function RSAEnc()
    {
        $rsa = new RSA();
        $rsa->loadKey($this->getPublicKey());
        $rsa->setEncryptionMode(RSA::ENCRYPTION_PKCS1);
        return base64_encode($rsa->encrypt($this->getAesKey()));
    }
}
