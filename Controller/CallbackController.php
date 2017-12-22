<?php
/**
 * Novalnet payment method module
 * This module is used for real time processing of
 * Novalnet transaction of customers.
 *
 * Copyright (c) Novalnet
 *
 * Released under the GNU General Public License
 * This free contribution made by request.
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * Script : CallbackController.php
 *
 */

namespace oe\novalnet\Controller;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use oe\novalnet\Classes\NovalnetUtil;

class CallbackController extends FrontendController
{
    protected $_sThisTemplate    = 'novalnetcallback.tpl';

    public $aCaptureParams;

    /** @Array Type of payment available - Level : 0 */
    protected $aPayments         = array( 'CREDITCARD', 'INVOICE_START', 'DIRECT_DEBIT_SEPA', 'GUARANTEED_INVOICE', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'PAYPAL', 'ONLINE_TRANSFER', 'IDEAL', 'EPS', 'GIROPAY', 'PRZELEWY24','CASHPAYMENT' );

    /** @Array Type of Chargebacks available - Level : 1 */
    protected $aChargebacks      = array( 'RETURN_DEBIT_SEPA', 'CREDITCARD_BOOKBACK', 'CREDITCARD_CHARGEBACK', 'PAYPAL_BOOKBACK', 'PRZELEWY24_REFUND', 'REFUND_BY_BANK_TRANSFER_EU', 'REVERSAL','CASHPAYMENT_REFUND' );

    /** @Array Type of CreditEntry payment and Collections available - Level : 2 */
    protected $aCollections      = array( 'INVOICE_CREDIT', 'CREDIT_ENTRY_CREDITCARD', 'CREDIT_ENTRY_SEPA', 'DEBT_COLLECTION_SEPA', 'DEBT_COLLECTION_CREDITCARD', 'ONLINE_TRANSFER_CREDIT','CASHPAYMENT_CREDIT' );

    protected $aPaymentGroups    = array(
                                            'novalnetcreditcard'     => array( 'CREDITCARD', 'CREDITCARD_BOOKBACK', 'CREDITCARD_CHARGEBACK', 'CREDIT_ENTRY_CREDITCARD', 'DEBT_COLLECTION_CREDITCARD', 'SUBSCRIPTION_STOP' ),
                                            'novalnetsepa'           => array( 'DIRECT_DEBIT_SEPA', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'RETURN_DEBIT_SEPA', 'DEBT_COLLECTION_SEPA', 'CREDIT_ENTRY_SEPA', 'SUBSCRIPTION_STOP' ),
                                            'novalnetideal'          => array( 'IDEAL', 'REFUND_BY_BANK_TRANSFER_EU', 'ONLINE_TRANSFER_CREDIT', 'REVERSAL' ),
                                            'novalnetonlinetransfer' => array( 'ONLINE_TRANSFER', 'REFUND_BY_BANK_TRANSFER_EU', 'ONLINE_TRANSFER_CREDIT', 'REVERSAL' ),
                                            'novalnetpaypal'         => array( 'PAYPAL', 'PAYPAL_BOOKBACK', 'SUBSCRIPTION_STOP', 'REFUND_BY_BANK_TRANSFER_EU' ),
                                            'novalnetprepayment'     => array( 'INVOICE_START', 'INVOICE_CREDIT', 'SUBSCRIPTION_STOP' ),
                                            'novalnetinvoice'        => array( 'INVOICE_START', 'INVOICE_CREDIT', 'GUARANTEED_INVOICE', 'SUBSCRIPTION_STOP' ),
                                            'novalneteps'            => array( 'EPS', 'REFUND_BY_BANK_TRANSFER_EU' ),
                                            'novalnetgiropay'        => array( 'GIROPAY', 'REFUND_BY_BANK_TRANSFER_EU' ),
                                            'novalnetprzelewy24'     => array( 'PRZELEWY24', 'PRZELEWY24_REFUND', 'REFUND_BY_BANK_TRANSFER_EU' ),
                                            'novalnetbarzahlen'     => array( 'CASHPAYMENT', 'CASHPAYMENT_CREDIT', 'CASHPAYMENT_REFUND' )
                                        );

    protected $aParamsRequired    = array('vendor_id', 'tid', 'payment_type', 'status', 'tid_status');

    protected $aAffParamsRequired = array('vendor_id', 'vendor_authcode', 'product_id', 'vendor_activation', 'aff_id', 'aff_authcode', 'aff_accesskey');

    /**
     * Returns name of template to render
     *
     * @return string
     */
    public function render()
    {
        return $this->_sThisTemplate;
    }

    /**
     * Handles the callback request
     *
     * @return boolean
     */
    public function handleRequest()
    {
        $this->aCaptureParams     = array_map('trim', $_REQUEST);
        $this->oNovalnetUtil = oxNew(NovalnetUtil::class);
        $this->blProcessTestMode  = $this->oNovalnetUtil->getNovalnetConfigValue('blCallbackTestMode');
        $this->oDb = \OxidEsales\Eshop\Core\DatabaseProvider::getDb(\OxidEsales\Eshop\Core\DatabaseProvider::FETCH_MODE_ASSOC);

        $this->_aViewData['sNovalnetMessage'] = '';
        if ($this->_validateCaptureParams())
        {
            // check callpack is to update the affiliate detail or process the callback for the transaction
            if (!empty($this->aCaptureParams['vendor_activation']))
            {
                $this->_updateAffiliateActivationDetails();
            } else {
                $this->_processNovalnetCallback();
            }
        }
        return false;
    }

    /**
     * Adds affiliate account
     *
     */
    private function _updateAffiliateActivationDetails()
    {
        $sNovalnetAffSql     = 'INSERT INTO novalnet_aff_account_detail (VENDOR_ID, VENDOR_AUTHCODE, PRODUCT_ID, PRODUCT_URL, ACTIVATION_DATE, AFF_ID, AFF_AUTHCODE, AFF_ACCESSKEY) VALUES ( ?, ?, ?, ?, ?, ?, ?, ? )';
        $aNovalnetAffDetails = array( $this->aCaptureParams['vendor_id'], $this->aCaptureParams['vendor_authcode'], (!empty($this->aCaptureParams['product_id']) ? $this->aCaptureParams['product_id'] : ''), (!empty($this->aCaptureParams['product_url']) ? $this->aCaptureParams['product_url'] : ''), (!empty($this->aCaptureParams['activation_date']) ? date('Y-m-d H:i:s', strtotime($this->aCaptureParams['activation_date'])) : ''), $this->aCaptureParams['aff_id'], $this->aCaptureParams['aff_authcode'], $this->aCaptureParams['aff_accesskey'] );
        $this->oDb->execute( $sNovalnetAffSql, $aNovalnetAffDetails );

        $sMessage = 'Novalnet callback script executed successfully with Novalnet account activation information';
        $sMessage = $this->_sendMail($sMessage) . $sMessage;
        $this->_displayMessage($sMessage);
    }

    /**
     * Validates the callback request
     *
     * @return boolean
     */
    private function _validateCaptureParams()
    {
        $sIpAllowed = gethostbyname('pay-nn.de');

        if (empty($sIpAllowed)) {
            $this->_displayMessage('Novalnet HOST IP missing');
            return false;
        }
        $sIpAddress = $this->_getIpAddress();
        $sMessage   = '';
        if (($sIpAddress != $sIpAllowed) && empty($this->blProcessTestMode)) {
            $this->_displayMessage('Novalnet callback received. Unauthorized access from the IP [' . $sIpAddress . ']');
            return false;
        }

        $aParamsRequired = (!empty($this->aCaptureParams['vendor_activation'])) ? $this->aAffParamsRequired : $this->aParamsRequired;

        $this->aCaptureParams['shop_tid'] = $this->aCaptureParams['tid'];

        if (in_array($this->aCaptureParams['payment_type'], array_merge($this->aChargebacks, $this->aCollections))) {
            array_push($aParamsRequired, 'tid_payment');
            $this->aCaptureParams['shop_tid'] = $this->aCaptureParams['tid_payment'];
        } elseif (!empty($this->aCaptureParams['subs_billing']) || $this->aCaptureParams['payment_type'] == 'SUBSCRIPTION_STOP') {
            array_push($aParamsRequired, 'signup_tid');
            $this->aCaptureParams['shop_tid'] = $this->aCaptureParams['signup_tid'];
        }
        foreach ($aParamsRequired as $sValue) {
            if (empty($this->aCaptureParams[$sValue]))
                $sMessage .= 'Required param ( ' . $sValue . ' ) missing!<br>';
        }

        if (!empty($sMessage)) {
            $this->_displayMessage($sMessage);
            return false;
        }

        if (!empty($this->aCaptureParams['vendor_activation']))
            return true;

        if (!is_numeric($this->aCaptureParams['status']) || $this->aCaptureParams['status'] <= 0) {
            $this->_displayMessage('Novalnet callback received. Status (' . $this->aCaptureParams['status'] . ') is not valid');
            return false;
        }

        foreach (array('signup_tid', 'tid_payment', 'tid') as $sTid) {
            if (!empty($this->aCaptureParams[$sTid]) && !preg_match('/^\d{17}$/', $this->aCaptureParams[$sTid])) {
                $this->_displayMessage('Novalnet callback received. Invalid TID [' . $this->aCaptureParams[$sTid] . '] for Order');
                return false;
            }
        }
        return true;
    }

    /**
     * Process the callback request
     *
     * @return void
     */
    private function _processNovalnetCallback()
    {
        if (!$this->_getOrderDetails())
            return;

        $sSql              = 'SELECT SUM(amount) AS paid_amount FROM novalnet_callback_history where ORDER_NO = "' . $this->aOrderDetails['ORDER_NO'] . '"';
        $aResult           = $this->oDb->getRow($sSql);
        $dPaidAmount       = $aResult['paid_amount'];
        $dAmount = $this->aOrderDetails['TOTAL_AMOUNT'] - $this->aOrderDetails['REFUND_AMOUNT'];
        $dFormattedAmount  = sprintf('%0.2f', ($this->aCaptureParams['amount']/100)) . ' ' . $this->aCaptureParams['currency']; // Formatted callback amount


        $iPaymentTypeLevel = $this->_getPaymentTypeLevel();

        if ($iPaymentTypeLevel === 0) {
            if ($this->aCaptureParams['subs_billing'] == 1 ) {
                // checks status of callback. if 100, then recurring processed or subscription canceled
                if ($this->aCaptureParams['status'] == '100' && in_array($this->aCaptureParams['tid_status'], array('100',  '90', '91', '98', '99', '85'))) {
                    $sOrderComments =  'Novalnet transaction details<br>' . 'Novalnet transaction ID' . ' ' . $this->aCaptureParams['tid'] . '<br>';
                    $sOrderComments .= !empty($this->aCaptureParams['test_mode']) ? 'Test order' .'<br>' : '';

                    $sNovalnetComments = '<br><br>Novalnet Callback Script executed successfully for the subscription TID:' . $this->aCaptureParams['signup_tid'] . ' with amount: ' . $dFormattedAmount . ' on ' . date('Y-m-d H:i:s') . '. Please refer PAID transaction in our Novalnet Merchant Administration with the TID: ' . $this->aCaptureParams['tid'];
                    $sPaidUntil = !empty($this->aCaptureParams['next_subs_cycle']) ? $this->aCaptureParams['next_subs_cycle'] : $this->aCaptureParams['paid_until'];
                    if (!empty($sPaidUntil)) {
                        $sNovalnetComments .= '<br>Next charging date: ' . date('Y-m-d H:i:s', strtotime($sPaidUntil));
                    }

                    $this->_createFollowupOrder($sOrderComments);
                } else {
                    $sTerminationReason = !empty($this->aCaptureParams['status_message']) ? $this->aCaptureParams['status_message'] : $this->aCaptureParams['termination_reason'];

                    $sUpdateSql = 'UPDATE novalnet_subscription_detail SET TERMINATION_REASON = "' . $sTerminationReason . '", TERMINATION_AT = "' . date('Y-m-d H:i:s') . '" WHERE ORDER_NO = "' . $this->aOrderDetails['ORDER_NO'] . '"';
                    $this->oDb->execute($sUpdateSql);

                    $sNovalnetComments = '<br><br>Novalnet callback script received. Subscription has been stopped for the TID: ' . $this->aCaptureParams['shop_tid'] . ' on ' . date('Y-m-d H:i:s') . '.<br>Reason for Cancellation: ' . $sTerminationReason;
                    $sUpdateSql = 'UPDATE oxorder SET NOVALNETCOMMENTS = CONCAT(IF(NOVALNETCOMMENTS IS NULL, "", NOVALNETCOMMENTS), "' . $sNovalnetComments . '") WHERE OXORDERNR ="' . $this->aOrderDetails['ORDER_NO'] . '"';
                    $this->oDb->execute($sUpdateSql);
                }

                $sNovalnetComments = $this->_sendMail($sNovalnetComments) . $sNovalnetComments;
                $this->_displayMessage($sNovalnetComments);
            } elseif (in_array($this->aCaptureParams['payment_type'], array('PAYPAL', 'PRZELEWY24')) && $this->aCaptureParams['status'] == '100' && $this->aCaptureParams['tid_status'] == '100') {
                if (!isset($dPaidAmount)) {
                    $sNovalnetCallbackSql     = 'INSERT INTO novalnet_callback_history (PAYMENT_TYPE, STATUS, ORDER_NO, AMOUNT, CURRENCY, CALLBACK_TID, ORG_TID, PRODUCT_ID, CALLBACK_DATE) VALUES ( ?, ?, ?, ?, ?, ?, ?, ?, ? )';
                    $aNovalnetCallbackDetails = array( $this->aCaptureParams['payment_type'], $this->aCaptureParams['status'], $this->aOrderDetails['ORDER_NO'], $this->aCaptureParams['amount'], $this->aCaptureParams['currency'], $this->aCaptureParams['tid'], $this->aCaptureParams['tid'], $this->aCaptureParams['product_id'], date('Y-m-d H:i:s') );
                    $this->oDb->execute($sNovalnetCallbackSql, $aNovalnetCallbackDetails);

                    $sNovalnetComments = '<br><br>Novalnet Callback Script executed successfully for the TID: ' . $this->aCaptureParams['tid'] . ' with amount ' . $dFormattedAmount . ' on ' . date('Y-m-d H:i:s');

                    $this->oDb->execute('UPDATE oxorder SET OXPAID = "' . date('Y-m-d H:i:s') . '", NOVALNETCOMMENTS = CONCAT(IF(NOVALNETCOMMENTS IS NULL, "", NOVALNETCOMMENTS), "' . $sNovalnetComments . '") WHERE OXORDERNR ="' . $this->aOrderDetails['ORDER_NO'] . '"');
                    $this->oDb->execute('UPDATE novalnet_transaction_detail SET GATEWAY_STATUS = "' . $this->aCaptureParams['tid_status'] . '" WHERE ORDER_NO ="' . $this->aOrderDetails['ORDER_NO'] . '"');
                    $sNovalnetComments = $this->_sendMail($sNovalnetComments) . $sNovalnetComments;
                    $this->_displayMessage($sNovalnetComments);
                } else {
                    $this->_displayMessage('Novalnet Callback script received. Order already Paid');
                }
            } elseif($this->aCaptureParams['payment_type']=='PRZELEWY24' && $this->aCaptureParams['status'] != '100' && $this->aCaptureParams['tid_status'] != '86') {
                    $sNovalnetComments = '<br><br>The transaction has been canceled due to: ' . $this->oNovalnetUtil->setNovalnetPaygateError($this->aCaptureParams);

                    $this->oDb->execute('UPDATE oxorder SET NOVALNETCOMMENTS = CONCAT(IF(NOVALNETCOMMENTS IS NULL, "", NOVALNETCOMMENTS), "' . $sNovalnetComments . '") WHERE OXORDERNR ="' . $this->aOrderDetails['ORDER_NO'] . '"');
                    $this->oDb->execute('UPDATE novalnet_transaction_detail SET GATEWAY_STATUS = "' . $this->aCaptureParams['tid_status'] . '" WHERE ORDER_NO ="' . $this->aOrderDetails['ORDER_NO'] . '"');
                    $sNovalnetComments = $this->_sendMail($sNovalnetComments) . $sNovalnetComments;
                    $this->_displayMessage($sNovalnetComments);
                }
            elseif ($this->aCaptureParams['status'] != '100' || !in_array($this->aCaptureParams['tid_status'], array('100', '85', '86', '90', '91', '98', '99'))) {
                $this->_displayMessage('Novalnet callback received. Status is not valid');
            } else {
                $this->_displayMessage('Novalnet Callback script received. Payment type ( ' . $this->aCaptureParams['payment_type'] . ' ) is not applicable for this process!');
            }
        } elseif ($iPaymentTypeLevel == 1 && $this->aCaptureParams['status'] == '100' && $this->aCaptureParams['tid_status'] == '100') {
            $sNovalnetComments = '<br><br>Novalnet callback received. Chargeback executed successfully for the TID: ' . $this->aCaptureParams['tid_payment'] . ' amount ' . $dFormattedAmount . ' on ' . date('Y-m-d H:i:s') . '. The subsequent TID: ' . $this->aCaptureParams['tid'];

            if (in_array($this->aCaptureParams['payment_type'], array('CREDITCARD_BOOKBACK', 'PAYPAL_BOOKBACK', 'PRZELEWY24_REFUND', 'REFUND_BY_BANK_TRANSFER_EU'))) {
                $sNovalnetComments = '<br><br>Novalnet callback received. Refund/Bookback executed successfully for the TID: ' . $this->aCaptureParams['tid_payment'] . ' amount ' . $dFormattedAmount . ' on ' . date('Y-m-d H:i:s') . '. The subsequent TID: ' . $this->aCaptureParams['tid'];
            }
            $sUpdateSql = 'UPDATE oxorder SET NOVALNETCOMMENTS = CONCAT(IF(NOVALNETCOMMENTS IS NULL, "", NOVALNETCOMMENTS), "' . $sNovalnetComments . '") WHERE OXORDERNR ="' . $this->aOrderDetails['ORDER_NO'] . '"';
            $this->oDb->execute($sUpdateSql);
            $sNovalnetComments = $this->_sendMail($sNovalnetComments) . $sNovalnetComments;
            $this->_displayMessage($sNovalnetComments);
        } elseif ($iPaymentTypeLevel == 2 && $this->aCaptureParams['status'] == '100' && $this->aCaptureParams['tid_status'] == '100') {
            if (!isset($dPaidAmount) || $dPaidAmount < $dAmount) {
                $dTotalAmount             = $dPaidAmount + $this->aCaptureParams['amount'];
                $sNovalnetCallbackSql     = 'INSERT INTO novalnet_callback_history (PAYMENT_TYPE, STATUS, ORDER_NO, AMOUNT, CURRENCY, CALLBACK_TID, ORG_TID, PRODUCT_ID, CALLBACK_DATE) VALUES ( ?, ?, ?, ?, ?, ?, ?, ?, ? )';
                $aNovalnetCallbackDetails = array( $this->aCaptureParams['payment_type'], $this->aCaptureParams['status'], $this->aOrderDetails['ORDER_NO'], $this->aCaptureParams['amount'], $this->aCaptureParams['currency'], $this->aCaptureParams['tid'], $this->aCaptureParams['tid_payment'], $this->aCaptureParams['product_id'], date('Y-m-d H:i:s') );
                $this->oDb->execute($sNovalnetCallbackSql, $aNovalnetCallbackDetails);

                $sNovalnetComments = '<br><br>Novalnet Callback Script executed successfully for the TID: ' . $this->aCaptureParams['tid_payment'] . ' with amount ' . $dFormattedAmount . ' on ' . date('Y-m-d H:i:s') . '. Please refer PAID transaction in our Novalnet Merchant Administration with the TID: ' . $this->aCaptureParams['tid'];

                $sUpdateSql = 'UPDATE oxorder SET NOVALNETCOMMENTS = CONCAT(IF(NOVALNETCOMMENTS IS NULL, "", NOVALNETCOMMENTS), "' . $sNovalnetComments . '") WHERE OXORDERNR ="' . $this->aOrderDetails['ORDER_NO'] . '"';

                if ($dAmount <= $dTotalAmount)
                    $sUpdateSql = 'UPDATE oxorder SET OXPAID = "' . date('Y-m-d H:i:s') . '", NOVALNETCOMMENTS = CONCAT(IF(NOVALNETCOMMENTS IS NULL, "", NOVALNETCOMMENTS), "' . $sNovalnetComments . '") WHERE OXORDERNR ="' . $this->aOrderDetails['ORDER_NO'] . '"';

                $this->oDb->execute($sUpdateSql);

                $sNovalnetComments = $this->_sendMail($sNovalnetComments) . $sNovalnetComments;

                $this->_displayMessage($sNovalnetComments);

            } else {
                $this->_displayMessage('Novalnet Callback script received. Order already Paid');
            }
        } elseif ($this->aCaptureParams['payment_type'] == 'SUBSCRIPTION_STOP') {
            $sTerminationReason = !empty($this->aCaptureParams['termination_reason']) ? $this->aCaptureParams['termination_reason'] : $this->aCaptureParams['status_message'];
            $sUpdateSql = 'UPDATE novalnet_subscription_detail SET TERMINATION_REASON = "' . $sTerminationReason . '", TERMINATION_AT = "' . date('Y-m-d H:i:s') . '" WHERE ORDER_NO = "' . $this->aOrderDetails['ORDER_NO'] . '"';
            $this->oDb->execute($sUpdateSql);

            $sNovalnetComments = '<br><br>Novalnet callback script received. Subscription has been stopped for the TID: ' . $this->aCaptureParams['shop_tid'] . ' on ' . date('Y-m-d H:i:s') . '.<br>Reason for Cancellation: ' . $sTerminationReason;

            $sUpdateSql = 'UPDATE oxorder SET NOVALNETCOMMENTS = CONCAT(IF(NOVALNETCOMMENTS IS NULL, "", NOVALNETCOMMENTS), "' . $sNovalnetComments . '") WHERE OXORDERNR ="' . $this->aOrderDetails['ORDER_NO'] . '"';
            $this->oDb->execute($sUpdateSql);

            $sNovalnetComments = $this->_sendMail($sNovalnetComments) . $sNovalnetComments;
            $this->_displayMessage($sNovalnetComments);
        } elseif ($this->aCaptureParams['status'] != '100' || $this->aCaptureParams['tid_status'] != '100') {
            $this->_displayMessage('Novalnet callback received. Status is not valid');
        } else {
            $this->_displayMessage('Novalnet callback script executed already');
        }
    }

    /**
     * Gets payment level of the callback request
     *
     * @return integer
     */
    private function _getPaymentTypeLevel()
    {
        if (in_array($this->aCaptureParams['payment_type'], $this->aPayments))
            return 0;
        elseif (in_array($this->aCaptureParams['payment_type'], $this->aChargebacks))
            return 1;
        elseif (in_array($this->aCaptureParams['payment_type'], $this->aCollections))
            return 2;
    }

    /**
     * Gets order details from the shop for the callback request
     *
     * @return boolean
     */
    private function _getOrderDetails()
    {
        $iOrderNo = !empty($this->aCaptureParams['order_no']) ? $this->aCaptureParams['order_no'] : (!empty($this->aCaptureParams['order_id']) ? $this->aCaptureParams['order_id'] : '');
        $sSql     = 'SELECT trans.ORDER_NO, trans.TOTAL_AMOUNT,trans.NNBASKET, trans.REFUND_AMOUNT, o.OXPAYMENTTYPE FROM novalnet_transaction_detail trans JOIN oxorder o ON o.OXORDERNR = trans.ORDER_NO where trans.tid = "' . $this->aCaptureParams['shop_tid'] . '"';

        $this->aOrderDetails = $this->oDb->getRow($sSql);

        // checks the payment type of callback and order
        if (empty($this->aOrderDetails['OXPAYMENTTYPE']) || !in_array($this->aCaptureParams['payment_type'], $this->aPaymentGroups[$this->aOrderDetails['OXPAYMENTTYPE']])) {
            $this->_displayMessage('Novalnet callback received. Payment Type [' . $this->aCaptureParams['payment_type'] . '] is not valid');
            return false;
        }

        // checks the order number in shop
        if (empty($this->aOrderDetails['ORDER_NO'])) {
            $this->_displayMessage('Transaction mapping failed');
            return false;
        }

        // checks order number of callback and shop only when the callback having the order number
        if (!empty($iOrderNo) && $iOrderNo != $this->aOrderDetails['ORDER_NO']) {
            $this->_displayMessage('Novalnet callback received. Order Number is not valid');
            return false;
        }
        return true;
    }

    /**
     * Displays the message
     *
     * @param string  $sMessage
     *
     */
    private function _displayMessage($sMessage)
    {
        $this->_aViewData['sNovalnetMessage'] = $sMessage;
    }

    /**
     * Gets the ip address
     *
     * @return string
     */
    private function _getIpAddress()
    {
        $oUtilsServer = oxNew(\OxidEsales\Eshop\Core\UtilsServer::class);
        $sIP = $oUtilsServer->getRemoteAddress();
        return (filter_var($sIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) ? '127.0.0.1' : $sIP;
    }

    /**
     * Sends messages as mail
     *
     * @param string $sMessage
     *
     * @return string
     */
    private function _sendMail($sMessage)
    {
        $blCallbackMail = $this->oNovalnetUtil->getNovalnetConfigValue('blCallbackMail');
        if (!empty($blCallbackMail)) {
            $oMail = oxNew(\OxidEsales\Eshop\Core\Email::class);
            $sToAddress    = $this->oNovalnetUtil->getNovalnetConfigValue('sCallbackMailToAddr');
            $sBccAddress   = $this->oNovalnetUtil->getNovalnetConfigValue('sCallbackMailBccAddr');
            $sEmailSubject = 'Novalnet Callback Script Access Report';
            $blValidTo     = false;
            // validates 'to' addresses
            if (!empty($sToAddress)) {
                $aToAddress = explode( ',', $sToAddress );
                foreach ($aToAddress as $sMailAddress) {
                    $sMailAddress = trim($sMailAddress);
                    if (oxNew(\OxidEsales\Eshop\Core\MailValidator::class)->isValidEmail($sMailAddress)) {
                        $oMail->setRecipient($sMailAddress);
                        $blValidTo = true;
                    }
                }
            }
            if (!$blValidTo)
                return 'Mail not sent<br>';

            // validates 'bcc' addresses
            if (!empty($sBccAddress)) {
                $aBccAddress = explode( ',', $sBccAddress );
                foreach ($aBccAddress as $sMailAddress) {
                    $sMailAddress = trim($sMailAddress);
                    if (oxNew(\OxidEsales\Eshop\Core\MailValidator::class)->isValidEmail($sMailAddress))
                        $oMail->AddBCC($sMailAddress);
                }
            }

            $oShop = $oMail->getShop();
            $oMail->setFrom($oShop->oxshops__oxorderemail->value);
            $oMail->setSubject( $sEmailSubject );
            $oMail->setBody( $sMessage );

            if ($oMail->send())
                return 'Mail sent successfully<br>';

        } else {
            return 'Mail not sent<br>';
        }

        return 'Mail not sent<br>';
    }

     /**
     * Create the new subscription order
     *
     * @param string $sOrderComments
     *
     */
    private function _createFollowupOrder($sOrderComments)
    {
        $oOrderNr = $this->aOrderDetails['ORDER_NO'];
        $aTxndetails = $this->aOrderDetails;

        // Get oxorder details
        $aOrderDetails = $this->oDb->getRow('SELECT * FROM oxorder where OXORDERNR = "' . $oOrderNr. '"');

        // Get oxorderarticles details
        $aOxorderarticles = $this->oDb->getAll('SELECT * FROM oxorderarticles where OXORDERID = "' . $aOrderDetails['OXID']. '"');

        // Load Order number
        $iCnt = oxNew(\OxidEsales\Eshop\Core\Counter::class)->getNext( 'oxOrder' );

        $iNextSubsCycle = !empty($this->aCaptureParams['next_subs_cycle']) ? $this->aCaptureParams['next_subs_cycle'] : (!empty($this->aCaptureParams['paid_until']) ? $this->aCaptureParams['paid_until'] : '');

        $sOrderComments .= (in_array($aTxndetails['OXPAYMENTTYPE'], array('novalnetinvoice','novalnetprepayment'))) ? $this->_getBankdetails().'<br><br>' : '';

        $sOrderComments .= 'Reference Order number '. $oOrderNr.'<br>';
        $sOrderComments .= 'Next charging date: '. $iNextSubsCycle;

        $this->_insertOxorderTable($aOrderDetails, $sOrderComments, $iCnt);

        foreach($aOxorderarticles as $key => $aOxorderArticle) {
           $sUId = $this->oNovalnetUtil->oSession->getVariable( 'sOxid');
           $this->_insertOxorderArticlesTable($sUId, $aOxorderArticle);
           $this->getOxAmount($aOrderDetails['OXID']);
        }

        $this->_insertNovalnetTranTable($oOrderNr, $iCnt);
        $this->_insertNovalnetSubDetailsTable($oOrderNr, $iCnt);
        $this->_insertNovalnetCallbackTable($oOrderNr, $iCnt);
        if (in_array($aTxndetails['OXPAYMENTTYPE'], array('novalnetinvoice','novalnetprepayment'))) {
            $this->_insertNovalnetPreInvTable($oOrderNr, $iCnt);
        }

        $this->_sendOrderByEmail($sUId, $aTxndetails['NNBASKET']);

        $this->oNovalnetUtil->oSession->deleteVariable( 'sOxid' );
    }

    /**
     * Insert the new order details on Oxorder table
     *
     * @param array $aOrderDetails
     * @param string $sOrderComments
     * @param double $iCnt
     *
     */
     protected function _insertOxorderTable($aOrderDetails, $sOrderComments, $iCnt)
     {

         $aOrder['OXID'] = $this->generateUId();
         $this->oNovalnetUtil->oSession->setVariable( 'sOxid', $aOrder['OXID']);
         $oOrder = oxNew(\OxidEsales\Eshop\Application\Model\Order::class);
         $oOrder->setId($aOrder['OXID']);

         $iInsertTime = time();
         $now = date('Y-m-d H:i:s', $iInsertTime);
         $oOrder->oxorder__oxshopid          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXSHOPID']);
         $oOrder->oxorder__oxuserid          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXUSERID']);
         $oOrder->oxorder__oxorderdate       = new \OxidEsales\Eshop\Core\Field($now);
         $oOrder->oxorder__oxordernr         = new \OxidEsales\Eshop\Core\Field($iCnt);
         $oOrder->oxorder__oxbillcompany     = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLCOMPANY']);
         $oOrder->oxorder__oxbillemail       = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLEMAIL']);
         $oOrder->oxorder__oxbillfname       = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLFNAME']);
         $oOrder->oxorder__oxbilllname       = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLLNAME']);
         $oOrder->oxorder__oxbillstreet      = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLSTREET']);
         $oOrder->oxorder__oxbillstreetnr    = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLSTREETNR']);
         $oOrder->oxorder__oxbilladdinfo     = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLADDINFO']);
         $oOrder->oxorder__oxbillustid       = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLUSTID']);
         $oOrder->oxorder__oxbillcity        = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLCITY']);
         $oOrder->oxorder__oxbillcountryid   = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLCOUNTRYID']);
         $oOrder->oxorder__oxbillstateid     = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLSTATEID']);
         $oOrder->oxorder__oxbillzip         = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLZIP']);
         $oOrder->oxorder__oxbillfon         = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLFON']);
         $oOrder->oxorder__oxbillfax         = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLFAX']);
         $oOrder->oxorder__oxbillsal         = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLSAL']);
         $oOrder->oxorder__oxdelcompany      = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELCOMPANY']);
         $oOrder->oxorder__oxdelfname        = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELLNAME']);
         $oOrder->oxorder__oxdellname        = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELCOMPANY']);
         $oOrder->oxorder__oxdelstreet       = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELCOMPANY']);
         $oOrder->oxorder__oxdelstreetnr     = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELSTREETNR']);
         $oOrder->oxorder__oxdeladdinfo      = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELADDINFO']);
         $oOrder->oxorder__oxdelcity         = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELCITY']);
         $oOrder->oxorder__oxdelcountryid    = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELCOUNTRYID']);
         $oOrder->oxorder__oxdelstateid      = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELSTATEID']);
         $oOrder->oxorder__oxdelzip          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELZIP']);
         $oOrder->oxorder__oxdelfon          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELFON']);
         $oOrder->oxorder__oxdelfax          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELFAX']);
         $oOrder->oxorder__oxdelsal          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELSAL']);
         $oOrder->oxorder__oxpaymentid       = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXPAYMENTID']);
         $oOrder->oxorder__oxpaymenttype     = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXPAYMENTTYPE']);
         $oOrder->oxorder__oxtotalnetsum     = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXTOTALNETSUM']);
         $oOrder->oxorder__oxtotalbrutsum    = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXTOTALBRUTSUM']);
         $oOrder->oxorder__oxtotalordersum   = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXTOTALORDERSUM']);
         $oOrder->oxorder__oxartvat1         = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXARTVAT1']);
         $oOrder->oxorder__oxartvatprice1    = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXARTVATPRICE1']);
         $oOrder->oxorder__oxartvat2         = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXARTVAT2']);
         $oOrder->oxorder__oxartvatprice2    = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXARTVATPRICE2']);
         $oOrder->oxorder__oxdelcost         = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELCOST']);
         $oOrder->oxorder__oxdelvat          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELVAT']);
         $oOrder->oxorder__oxpaycost         = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXPAYCOST']);
         $oOrder->oxorder__oxpayvat          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXPAYVAT']);
         $oOrder->oxorder__oxwrapcost        = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXWRAPCOST']);
         $oOrder->oxorder__oxwrapvat         = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXWRAPVAT']);
         $oOrder->oxorder__oxgiftcardcost    = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXGIFTCARDCOST']);
         $oOrder->oxorder__oxgiftcardvat     = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXGIFTCARDVAT']);
         $oOrder->oxorder__oxcardid          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXCARDID']);
         $oOrder->oxorder__oxcardtext        = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXCARDTEXT']);
         $oOrder->oxorder__oxdiscount        = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDISCOUNT']);
         $oOrder->oxorder__oxexport          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXEXPORT']);
         $oOrder->oxorder__oxbillnr          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLNR']);
         $oOrder->oxorder__oxbilldate        = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXBILLDATE']);
         $oOrder->oxorder__oxtrackcode       = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXTRACKCODE']);
         $oOrder->oxorder__oxsenddate        = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXSENDDATE']);
         $oOrder->oxorder__oxremark          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXREMARK']);
         $oOrder->oxorder__oxvoucherdiscount = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXVOUCHERDISCOUNT']);
         $oOrder->oxorder__oxcurrency        = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXCURRENCY']);
         $oOrder->oxorder__oxcurrate         = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXCURRATE']);
         $oOrder->oxorder__oxfolder          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXFOLDER']);
         $oOrder->oxorder__oxtransid         = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXTRANSID']);
         $oOrder->oxorder__oxpayid           = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXPAYID']);
         $oOrder->oxorder__oxxid             = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXXID']);
         $oOrder->oxorder__oxpaid            = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXPAID']);
         $oOrder->oxorder__oxstorno          = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXSTORNO']);
         $oOrder->oxorder__oxip              = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXIP']);
         $oOrder->oxorder__oxtransstatus     = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXTRANSSTATUS']);
         $oOrder->oxorder__oxlang            = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXLANG']);
         $oOrder->oxorder__oxinvoicenr       = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXINVOICENR']);
         $oOrder->oxorder__oxdeltype         = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXDELTYPE']);
         $oOrder->oxorder__oxtsprotectid     = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXTSPROTECTID']);
         $oOrder->oxorder__oxtsprotectcosts  = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXTSPROTECTCOSTS']);
         $oOrder->oxorder__oxtimestamp       = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXTIMESTAMP']);
         $oOrder->oxorder__oxisnettomode     = new \OxidEsales\Eshop\Core\Field($aOrderDetails['OXISNETTOMODE']);
         $oOrder->oxorder__novalnetcomments  = new \OxidEsales\Eshop\Core\Field($sOrderComments);
         $oOrder->save();

    }

    /**
     * Insert the new order articles details on OxorderArticles table
     *
     * @param array $aOxorderArticle
     *
     */
     protected function _insertOxorderArticlesTable($sUId, $aOxorderArticle)
     {
        $sUniqueid = $this->generateUId();
        $oOrderArticle = oxNew(\OxidEsales\Eshop\Application\Model\OrderArticle::class);
        $oOrderArticle->oxorderarticles__oxoxid    = new \OxidEsales\Eshop\Core\Field($sUniqueid);
        $oOrderArticle->oxorderarticles__oxorderid = new \OxidEsales\Eshop\Core\Field($sUId);
        $oOrderArticle->oxorderarticles__oxamount  = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXAMOUNT']);
        $oOrderArticle->oxorderarticles__oxartid   = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXARTID']);
        $oOrderArticle->oxorderarticles__oxartnum  = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXARTNUM']);
        $oOrderArticle->oxorderarticles__oxtitle   = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXTITLE']);
        $oOrderArticle->oxorderarticles__oxshortdesc  = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXSHORTDESC']);
        $oOrderArticle->oxorderarticles__oxselvariant = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXSELVARIANT']);
        $oOrderArticle->oxorderarticles__oxnetprice   = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXNETPRICE']);
        $oOrderArticle->oxorderarticles__oxbrutprice  = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXBRUTPRICE']);
        $oOrderArticle->oxorderarticles__oxvatprice   = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXVATPRICE']);
        $oOrderArticle->oxorderarticles__oxvat        = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXVAT']);
        $oOrderArticle->oxorderarticles__oxpersparam  = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXPERSPARAM']);
        $oOrderArticle->oxorderarticles__oxprice      = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXPRICE']);
        $oOrderArticle->oxorderarticles__oxbprice     = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXBPRICE']);
        $oOrderArticle->oxorderarticles__oxnprice     = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXNPRICE']);
        $oOrderArticle->oxorderarticles__oxwrapid     = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXWRAPID']);
        $oOrderArticle->oxorderarticles__oxexturl     = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXEXTURL']);
        $oOrderArticle->oxorderarticles__oxurldesc    = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXURLDESC']);
        $oOrderArticle->oxorderarticles__oxurlimg     = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXURLIMG']);
        $oOrderArticle->oxarticles__oxthumb           = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXTHUMB']);
        $oOrderArticle->oxarticles__oxpic1            = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXPIC1']);
        $oOrderArticle->oxarticles__oxpic2            = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXPIC2']);
        $oOrderArticle->oxarticles__oxpic3            = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXPIC3']);
        $oOrderArticle->oxarticles__oxpic4            = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXPIC4']);
        $oOrderArticle->oxarticles__oxpic5            = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXPIC5']);
        $oOrderArticle->oxarticles__oxweight          = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXWEIGHT']);
        $oOrderArticle->oxarticles__oxstock           = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXSTOCK']);
        $oOrderArticle->oxarticles__oxdelivery        = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXDELIVERY']);
        $oOrderArticle->oxarticles__oxinsert          = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXINSERT']);
        $iInsertTime = time();
        $now = date('Y-m-d H:i:s', $iInsertTime);
        $oOrderArticle->oxorderarticles__oxtimestamp  = new \OxidEsales\Eshop\Core\Field( $now );
        $oOrderArticle->oxarticles__oxlength          = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXLENGTH']);
        $oOrderArticle->oxarticles__oxwidth           = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXWIDTH']);
        $oOrderArticle->oxarticles__oxheight          = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXHEIGHT']);
        $oOrderArticle->oxarticles__oxfile            = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXFILE']);
        $oOrderArticle->oxarticles__oxsearchkeys      = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXSEARCHKEYS']);
        $oOrderArticle->oxarticles__oxtemplate        = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXTEMPLATE']);
        $oOrderArticle->oxarticles__oxquestionemail   = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXQUESTIONEMAIL']);
        $oOrderArticle->oxarticles__oxissearch        = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXISSEARCH']);
        $oOrderArticle->oxarticles__oxfolder          = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXFOLDER']);
        $oOrderArticle->oxarticles__oxsubclass        = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXSUBCLASS']);
        $oOrderArticle->oxorderarticles__oxstorno     = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXSTORNO']);
        $oOrderArticle->oxorderarticles__oxordershopid = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXORDERSHOPID']);
        $oOrderArticle->oxorderarticles__oxisbundle    = new \OxidEsales\Eshop\Core\Field($aOxorderArticle['OXISBUNDLE']);
        $oOrderArticle->save();

    }

    /**
     * Get the Product Quantity and update the quantity in oxarticles table
     *
     * @param integer $oxAmount
     *
     */
    public function getOxAmount($oxAmount)
    {

        $this->oDb = \OxidEsales\Eshop\Core\DatabaseProvider::getDb(\OxidEsales\Eshop\Core\DatabaseProvider::FETCH_MODE_ASSOC);

        $sSql = 'SELECT OXARTID, OXAMOUNT FROM oxorderarticles where OXORDERID = "' .  $oxAmount. '"';
        $dgetOxAmount           = $this->oDb->getRow($sSql);

        $sArtSql = 'SELECT OXSTOCK FROM oxarticles where OXID = "' .  $dgetOxAmount['OXARTID']. '"';
        $dgetArtCount = $this->oDb->getRow($sArtSql);
        $dProductId = $dgetArtCount['OXSTOCK'] - $dgetOxAmount['OXAMOUNT'];
        if ( $dProductId < 0) {
            $dProductId = 0;
        }
        // Stock updated in oxarticles table
        $sUpdateSql = 'UPDATE oxarticles SET OXSTOCK = "' . $dProductId . '" WHERE OXID ="' . $dgetOxAmount['OXARTID'] . '"';

        $this->oDb->execute($sUpdateSql);

    }

    /**
     * Generate the uniqid
     *
     */
    public function generateUId()
    {
        return md5(uniqid('', true) . '|' . microtime());
    }

    /**
     * Insert new order details on Novalnet transaction table
     *
     * @param integer $oOrderNr
     * @param integer $iCnt
     */
    private function _insertNovalnetTranTable($oOrderNr, $iCnt)
    {

        $this->oDb  = \OxidEsales\Eshop\Core\DatabaseProvider::getDb(\OxidEsales\Eshop\Core\DatabaseProvider::FETCH_MODE_ASSOC);
        $aNNTransDetails = $this->oDb->getRow('SELECT * from novalnet_transaction_detail where ORDER_NO ='.$oOrderNr);

        // Insert new order details in Novalnet transaction details table
        $sInsertSql = 'INSERT INTO novalnet_transaction_detail (VENDOR_ID, PRODUCT_ID, AUTH_CODE, TARIFF_ID, TID, ORDER_NO, SUBS_ID, PAYMENT_ID, PAYMENT_TYPE, AMOUNT, CURRENCY, STATUS, GATEWAY_STATUS, TEST_MODE, CUSTOMER_ID, ORDER_DATE, REFUND_AMOUNT, TOTAL_AMOUNT, PROCESS_KEY, MASKED_DETAILS, ZERO_TRXNDETAILS, ZERO_TRXNREFERENCE, ZERO_TRANSACTION, REFERENCE_TRANSACTION, NNBASKET) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $aInsertValues  = array($aNNTransDetails['VENDOR_ID'], $aNNTransDetails['PRODUCT_ID'], $aNNTransDetails['AUTH_CODE'], $aNNTransDetails['TARIFF_ID'], $this->aCaptureParams['tid'], $iCnt, $aNNTransDetails['SUBS_ID'], $aNNTransDetails['PAYMENT_ID'], $aNNTransDetails['PAYMENT_TYPE'], $this->aCaptureParams['amount'], $aNNTransDetails['CURRENCY'], $aNNTransDetails['STATUS'], $aNNTransDetails['GATEWAY_STATUS'], $aNNTransDetails['TEST_MODE'], $aNNTransDetails['CUSTOMER_ID'], date('Y-m-d H:i:s'), $this->aCaptureParams['amount'], $this->aCaptureParams['amount'], $aNNTransDetails['PROCESS_KEY'], $aNNTransDetails['MASKED_DETAILS'], $aNNTransDetails['ZERO_TRXNDETAILS'], $aNNTransDetails['ZERO_TRXNREFERENCE'], $aNNTransDetails['ZERO_TRANSACTION'], $aNNTransDetails['REFERENCE_TRANSACTION'], $aNNTransDetails['NNBASKET']);

        $this->oDb->execute( $sInsertSql, $aInsertValues );

    }

    /**
     * Insert new order details on Novalnet subscription table
     *
     * @param integer $oOrderNr
     * @param integer $iCnt
     */
    private function _insertNovalnetSubDetailsTable($oOrderNr, $iCnt)
    {
        $this->oDb  = \OxidEsales\Eshop\Core\DatabaseProvider::getDb(\OxidEsales\Eshop\Core\DatabaseProvider::FETCH_MODE_ASSOC);
        $aNNSubsDetails = $this->oDb->getRow('SELECT * from novalnet_subscription_detail where ORDER_NO ='.$oOrderNr);

        // Insert new order details in Novalnet subscription details table
        $sInsertSql = 'INSERT INTO novalnet_subscription_detail (ORDER_NO, SUBS_ID, TID, SIGNUP_DATE, TERMINATION_REASON, TERMINATION_AT) VALUES (?, ?, ?, ?, ?, ?)';

        $aInsertValues = array($iCnt, $aNNSubsDetails['SUBS_ID'], $aNNSubsDetails['TID'], date('Y-m-d H:i:s'), $aNNSubsDetails['TERMINATION_REASON'], $aNNSubsDetails['TERMINATION_AT']);

        $this->oDb->execute( $sInsertSql, $aInsertValues );
    }

    /**
     * Insert new order details in Novalnet Callback table
     *
     * @param integer $oOrderNr
     * @param integer $iCnt
     */
    private function _insertNovalnetCallbackTable($oOrderNr, $iCnt)
    {
        $this->oDb = \OxidEsales\Eshop\Core\DatabaseProvider::getDb(\OxidEsales\Eshop\Core\DatabaseProvider::FETCH_MODE_ASSOC);
        $aNNCallbackDetails = $this->oDb->getRow('SELECT * from novalnet_callback_history where ORDER_NO ='.$oOrderNr);

        // Insert new order details in Novalnet subscription details table
        $sInsertSql = 'INSERT INTO novalnet_callback_history (PAYMENT_TYPE, STATUS, ORDER_NO, AMOUNT, CURRENCY, CALLBACK_TID, ORG_TID, PRODUCT_ID, CALLBACK_DATE) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';

        $aInsertValues = array($aNNCallbackDetails['PAYMENT_TYPE'], $aNNCallbackDetails['STATUS'], $iCnt, $aNNCallbackDetails['AMOUNT'], $aNNCallbackDetails['CURRENCY'], $aNNCallbackDetails['CALLBACK_TID'], $aNNCallbackDetails['ORG_TID'], $aNNCallbackDetails['PRODUCT_ID'], $aNNCallbackDetails['CALLBACK_DATE']);

        $this->oDb->execute( $sInsertSql, $aInsertValues );
    }

    /**
     * Insert new order details in Novalnet Preinvoice table
     *
     * @param integer $oOrderNr
     * @param integer $iCnt
     */
    public function _insertNovalnetPreInvTable($oOrderNr, $iCnt)
    {
        $this->oDb  = \OxidEsales\Eshop\Core\DatabaseProvider::getDb(\OxidEsales\Eshop\Core\DatabaseProvider::FETCH_MODE_ASSOC);
        $aNNPreInvDetails = $this->oDb->getRow('SELECT * from novalnet_preinvoice_transaction_detail where ORDER_NO ='.$oOrderNr);

         // Insert new order details in Novalnet Preinvoice transaction details table
        $sInsertSql = 'INSERT INTO novalnet_preinvoice_transaction_detail (ORDER_NO, TID, TEST_MODE, ACCOUNT_HOLDER, BANK_IBAN, BANK_BIC, BANK_NAME, BANK_CITY, AMOUNT, CURRENCY, INVOICE_REF, DUE_DATE, ORDER_DATE, PAYMENT_REF) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $aNNPreInvDetails['DUE_DATE'] = date('d.m.Y', strtotime($this->aCaptureParams['due_date']));
        $aInsertValues = array($iCnt, $this->aCaptureParams['tid'], $aNNPreInvDetails['TEST_MODE'], $this->aCaptureParams['invoice_account_holder'], $this->aCaptureParams['invoice_iban'], $this->aCaptureParams['invoice_bic'], $this->aCaptureParams['invoice_bankname'], $this->aCaptureParams['invoice_bankplace'], $this->aCaptureParams['amount'], $this->aCaptureParams['CURRENCY'], $aNNPreInvDetails['INVOICE_REF'], $aNNPreInvDetails['DUE_DATE'], date('Y-m-d H:i:s'), $aNNPreInvDetails['PAYMENT_REF']);

        $this->oDb->execute( $sInsertSql, $aInsertValues );
    }

    /**
     * Get Invoice prepayment details
     *
     */
    protected function _getBankdetails()
    {
        $oLang = \OxidEsales\Eshop\Core\Registry::getLang();
        $sInvoiceComments = $oLang->translateString('NOVALNET_INVOICE_COMMENTS_TITLE');
        if (!empty($this->aCaptureParams['due_date'])) {
            $sInvoiceComments .= $oLang->translateString('NOVALNET_DUE_DATE') . date('d.m.Y', strtotime($this->aCaptureParams['due_date']));
		}
        $sInvoiceComments .= $oLang->translateString('NOVALNET_ACCOUNT') . $this->aCaptureParams['invoice_account_holder'];
        $sInvoiceComments .= '<br>IBAN: ' . $this->aCaptureParams['invoice_iban'];
        $sInvoiceComments .= '<br>BIC: '  . $this->aCaptureParams['invoice_bic'];
        $sInvoiceComments .= '<br>Bank: ' . $this->aCaptureParams['invoice_bankname'] . ' ' . $this->aCaptureParams['invoice_bankplace'];
        $sInvoiceComments .= $oLang->translateString('NOVALNET_AMOUNT') . $this->aCaptureParams['amount'] . ' ' . $this->aCaptureParams['currency'];

       return $sInvoiceComments;
    }

    /**
     * Send new order mail for customer & Owner
     *
     * @param string $oOrderId
     * @param object $oBasketValue
     *
     */
    protected function _sendOrderByEmail($oOrderId, $oBasketValue)
    {

        $oOrder = oxNew(\OxidEsales\Eshop\Application\Model\Order::class);
        $oOrder->load($oOrderId);

        $oUser = oxNew(\OxidEsales\Eshop\Application\Model\User::class);
        $oUser->load($oOrder->oxorder__oxuserid->value);

        $oOrder->_oUser = $oUser;

        $oPayment = oxNew(\OxidEsales\Eshop\Application\Model\UserPayment::class);
        $oPayment->load($oOrder->oxorder__oxpaymentid->value);
        $oOrder->_oPayment = $oPayment;

        $oBasket = unserialize($oBasketValue);
        $oOrder->_oBasket = $oBasket;

        $oxEmail = oxNew(\OxidEsales\Eshop\Core\Email::class);

        // send order email to user
        $oxEmail->sendOrderEMailToUser( $oOrder );

        // send order email to shop owner
        $oxEmail->sendOrderEMailToOwner( $oOrder );

    }
}

/*
Level 0 Payments:
-----------------
CREDITCARD
INVOICE_START
DIRECT_DEBIT_SEPA
GUARANTEED_INVOICE
GUARANTEED_DIRECT_DEBIT_SEPA
PAYPAL
ONLINE_TRANSFER
IDEAL
EPS
GIROPAY
PRZELEWY24

Level 1 Payments:
-----------------
RETURN_DEBIT_SEPA
GUARANTEED_RETURN_DEBIT_DE
REVERSAL
CREDITCARD_BOOKBACK
CREDITCARD_CHARGEBACK
REFUND_BY_BANK_TRANSFER_EU
PRZELEWY24_REFUND

Level 2 Payments:
-----------------
INVOICE_CREDIT
CREDIT_ENTRY_CREDITCARD
CREDIT_ENTRY_SEPA
CREDIT_ENTRY_DE
DEBT_COLLECTION_SEPA
DEBT_COLLECTION_CREDITCARD
DEBT_COLLECTION_DE
DEBT_COLLECTION_AT
*/
?>
