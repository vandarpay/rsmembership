<?php
/**
 * @package       RSMembership!
 * @copyright (C) 2009-2020 www.rsjoomla.com
 * @license       GPL, http://www.gnu.org/licenses/gpl-2.0.html
 */
/**
 * @plugin RSMembership Vandar Payment
 * @author Meysam Razmi(meysamrazmi), vispa
 */

ini_set('display_errors', 1);
defined('_JEXEC') or die('Restricted access');
require_once JPATH_ADMINISTRATOR . '/components/com_rsmembership/helpers/rsmembership.php';

class plgSystemRSMembershipVandar extends JPlugin
{
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        // load languages
        $this->loadLanguage('plg_system_rsmembership', JPATH_ADMINISTRATOR);
        $this->loadLanguage('plg_system_rsmembershipvandar', JPATH_ADMINISTRATOR);

        RSMembership::addPlugin( $this->translate('OPTION_NAME'), 'rsmembershipvandar');
    }

    /**
     * call when payment starts
     *
     * @param $plugin
     * @param $data
     * @param $extra
     * @param $membership
     * @param $transaction
     * @param $html
     */
    public function onMembershipPayment($plugin, $data, $extra, $membership, $transaction, $html)
    {
        $app = JFactory::getApplication();

        try {
            if ($plugin != 'rsmembershipvandar')
                return;

            $api_key     = trim($this->params->get('api_key'));
            $extra_total = 0;
            foreach ($extra as $row) {
                $extra_total += $row->price;
            }

            $amount = $transaction->price + $extra_total;
            $amount *= $this->params->get('currency') == 'rial' ? 1 : 10;

            $transaction->custom = md5($transaction->params . ' ' . time());
            if ($membership->activation == 2) {
                $transaction->status = 'completed';
            }
            $transaction->store();

            $callback = JURI::base() . 'index.php?option=com_rsmembership&vandarPayment=1&factorNumber='. $transaction->id;
            $callback = JRoute::_($callback, false);
            $session  = JFactory::getSession();
            $session->set('transaction_custom', $transaction->custom);
            $session->set('membership_id', $membership->id);

            $data = [
                'api_key'		=> $api_key,
                'amount'		=> $amount,
                'callback_url'	=> $callback,
                'mobile_number'	=> !empty($data->fields['phone'])? $data->fields['phone'] : '',
                'factorNumber'	=> $transaction->id,
                'description'	=> htmlentities( $this->translate('PARAMS_DESC') . $transaction->id, ENT_COMPAT, 'utf-8'),
            ];

            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, 'https://ipg.vandar.io/api/v3/send' );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
            curl_setopt( $ch, CURLOPT_POST,true);
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

            $result      = curl_exec( $ch );
            $result      = json_decode( $result );
            $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            curl_close( $ch );

            if ( $http_status != 200 || empty( $result ) || empty( $result->token ) || $result->status != 1 )
            {
                $transaction->status = 'denied';
                $transaction->store();

                $msg = '';
                foreach ($result->errors as $err){
                    $msg .= $err . '<br>';
                    RSMembership::saveTransactionLog($err, $transaction->id);
                }

                throw new Exception($msg);
            }

            RSMembership::saveTransactionLog( $this->translate('LOG_GOTO_BANK'), $transaction->id );
            $app->redirect('https://ipg.vandar.io/v3/' . $result->token);

            exit;
        }
        catch (Exception $e) {
            $app->redirect(JRoute::_(JURI::base() . 'index.php/component/rsmembership/view-membership-details/' . $membership->id, false), $e->getMessage(), 'error');
            exit;
        }
    }

    public function getLimitations() {
        $msg = $this->translate('LIMITAION');
        return $msg;
    }

    /**
     * after payment completed
     * calls function onPaymentNotification()
     */
    public function onAfterDispatch()
    {
        $app = JFactory::getApplication();
        if ($app->input->getBoolean('vandarPayment')) {
            $this->onPaymentNotification($app);
        }
    }

    /**
     * process payment verification and approve subscription
     * @param $app
     */
    protected function onPaymentNotification($app)
    {
        $input    = $app->input;
        $status   = empty( $input->get->get( 'payment_status' ) ) ? NULL : $input->get->get( 'payment_status' );
        $token    = empty( $input->get->get( 'token' ) ) ? NULL : $input->get->get( 'token' );
        $order_id = empty( $input->get->get( 'factorNumber' ) ) ? NULL : $input->get->get( 'factorNumber' );

        $session  = JFactory::getSession();

        $transaction_custom = $session->get('transaction_custom');

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*')
            ->from($db->quoteName('#__rsmembership_transactions'))
            ->where($db->quoteName('status') . ' != ' . $db->quote('completed'))
            ->where($db->quoteName('custom') . ' = ' . $db->quote($transaction_custom));
        $db->setQuery($query);
        $transaction = @$db->loadObject();

        try {
            if ( empty( $token ) || empty( $order_id ) )
                throw new Exception( $this->translate('ERROR_EMPTY_PARAMS') );

            if (!$transaction)
                throw new Exception( $this->translate('ERROR_NOT_FOUND') );

            // Check double spending.
            if ( $transaction->id != $order_id )
                throw new Exception( $this->translate('ERROR_WRONG_PARAMS') );

            if ( $status != 'OK' )
                throw new Exception( $this->translate('ERROR_CANCELED') );

            $api_key = $this->params->get( 'api_key', '' );
            $data = [
                'token'   => $token,
                'api_key' => $api_key,
            ];
            $ch = curl_init();
            curl_setopt( $ch, CURLOPT_URL, 'https://ipg.vandar.io/api/v3/verify' );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, TRUE );
            curl_setopt( $ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json'] );

            $result      = curl_exec( $ch );
            $result      = json_decode( $result );
            $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            curl_close( $ch );

            if ( $http_status != 200 || $result->status != 1 ) {
                $msg = $this->translate('ERROR_FAILED_VERIFY');
                foreach ($result->errors as $err){
                    $msg .= '<br>'. $err;
                }
                throw new Exception($msg);
            }

            $verify_track_id = empty( $result->transId ) ? NULL : $result->transId;

            if ($result->status == 1) {
                $query->clear();
                $query->update($db->quoteName('#__rsmembership_transactions'))
                    ->set($db->quoteName('hash') . ' = ' . $db->quote($verify_track_id))
                    ->where($db->quoteName('id') . ' = ' . $db->quote($transaction->id));

                $db->setQuery($query);
                $db->execute();

                $membership_id = $session->get('membership_id');

                if (!$membership_id)
                    throw new Exception( $this->translate('ERROR_NOT_FOUND'));

                $query->clear()
                    ->select('activation')
                    ->from($db->quoteName('#__rsmembership_memberships'))
                    ->where($db->quoteName('id') . ' = ' . $db->quote((int)$membership_id));
                $db->setQuery($query);
                $activation = $db->loadResult();

                if ($activation) {// activation == 0 => activation is manual
                    RSMembership::approve($transaction->id);
                }

                $msg = $this->vandar_get_filled_message( $verify_track_id, $transaction->id, 'success_massage' );
                RSMembership::saveTransactionLog($msg, $transaction->id);

                $app->redirect(JRoute::_(JURI::base() . 'index.php?option=com_rsmembership&view=mymemberships', false), $msg, 'message');
            }

            $msg = $this->vandar_get_filled_message( $verify_track_id, $transaction->id, 'failed_massage' );
            throw new Exception($msg);

        } catch (Exception $e) {
            if($transaction){
                RSMembership::deny($transaction->id);
                RSMembership::saveTransactionLog($e->getMessage(), $transaction->id );
            }
            $app->enqueueMessage($e->getMessage(), 'error');
        }
    }

    /**
     * fill message in gateway setting with track_id and order_id
     *
     * @param $track_id
     * @param $order_id
     * @param $type | success or error
     *
     * @return String
     */
    public function vandar_get_filled_message( $track_id, $order_id, $type ) {
        return str_replace( [ "{track_id}", "{order_id}" ], [
            $track_id,
            $order_id,
        ], $this->params->get( $type, '' ) );
    }

    /**
     * translate plugin language files
     * @param $key
     * @return mixed
     */
    protected function translate($key)
    {
        return JText::_('PLG_RSM_VANDAR_' . strtoupper($key));
    }
}