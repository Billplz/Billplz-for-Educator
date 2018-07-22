<?php

class Edr_Gateway_Billplz extends Edr_Gateway_Base
{
    protected $api_key;
    protected $x_signature;
    protected $collection_id;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->id = 'billplz';
        $this->title = __('Billplz', 'educator');

        $this->init_options(array(
        'api_key' => array(
            'type'  => 'text',
            'label' => 'API Secret Key',
            'id'    => 'edr-billplz-api-key',
            'description' => 'Plugin will determine API Key is belong to production or staging environment automatically.',
        ),

        'x_signature' => array(
            'type'  => 'text',
            'label' => 'X Signature Key',
            'id'    => 'edr-billplz-x-signature',
        ),

        'collection_id' => array(
            'type'  => 'text',
            'label' => 'Collection ID',
            'id'    => 'edr-billplz-collection-id',
        ),

        'thankyou_message' => array(
            'type'      => 'textarea',
            'label'     => __('Thank you message', 'educator'),
            'id'        => 'edr-billplz-thankyou-message',
            'rich_text' => true,
        ),
        ));

        add_action('edr_pay_' . $this->get_id(), array( $this, 'pay_page' ));
        add_action('edr_thankyou_' . $this->get_id(), array( $this, 'thankyou_page' ));
        add_action('edr_request_billplzcallback', array( $this, 'process_callback' ));
    }

    /**
     * Process payment.
     *
     * @return array
     */
    public function process_payment($object_id, $user_id = null, $payment_type = 'course', $atts = array())
    {
        if (! $user_id) {
            $user_id = get_current_user_id();
        }

        if (! $user_id) {
            return array( 'redirect' => home_url('/') );
        }

        $payment = $this->create_payment($object_id, $user_id, $payment_type, $atts);
        $redirect_url = edr_get_endpoint_url(
            'edr-pay',
            ( $payment->ID ? $payment->ID : '' ),
            get_permalink(edr_get_page_id('payment'))
        );

        return array(
        'status'   => 'pending',
        'redirect' => $redirect_url,
        'payment'  => $payment,
        );
    }

    /**
     * Output the form to the step 2 (pay page) of the payment page.
     */
    public function pay_page()
    {
        $api_key = trim($this->get_option('api_key'));
        $collection_id = trim($this->get_option('collection_id'));
        
        $payment_id = intval(get_query_var('edr-pay'));
        if (! $payment_id) {
            return;
        }

        $user = wp_get_current_user();

        if (0 == $user->ID) {
            return;
        }

        $payment = edr_get_payment($payment_id);

        // The payment must exist in the database
        // and it must belong to the current user.
        if (! $payment->ID || $user->ID != $payment->user_id) {
            return;
        }

        $post = get_post($payment->object_id);

        if (! $post) {
            return;
        }

        $return_url = '';
        $payment_page_id = edr_get_page_id('payment');
        if ($payment_page_id) {
            $return_url = edr_get_endpoint_url('edr-payment', ( $payment->ID ? $payment->ID : '' ), get_permalink($payment_page_id));
        }

        $currency = esc_attr(edr_get_currency());
        if ($currency !== 'MYR') {
            return;
        }

        $user_name = trim($user->user_firstname . ' ' . $user->user_lasttname);
        $user_name = empty($user_name) ? $user->user_login : $user_name;

        $parameter = array(
                'collection_id' => $collection_id,
                'email' => sanitize_email($user->user_email),
                /* 'mobile'=> '', No mobile schema made in educator*/
                'name' => $user_name,
                'amount' => strval($payment->amount * 100),
                'callback_url' => Edr_RequestDispatcher::get_url('billplzcallback'),
                'description' => mb_substr($post->post_title, 0, 199)
            );
            $optional = array(
                'redirect_url' => $return_url,
                /* Just reserve reference_1 for premium usage */
                'reference_2_label' => 'ID',
                'reference_2' => intval($payment->ID)
            );

        $connnect = (new EducatorBillplzWPConnect($api_key))->detectMode();
        $billplz = new EducatorBillplzAPI($connnect);
        list($rheader, $rbody) = $billplz->toArray($billplz->createBill($parameter, $optional));

        if ($rheader !== 200) {
            return;
        }

        echo '<div id="billplz-redirect-notice" style="display: none;">' . 'Redirecting to Billplz...' . '</div>';
        echo '<script>(function () {
                    function goToBillplz()
                    {
                        window.location.replace("'.$rbody['url'].'");
                    }
                    if (typeof jQuery === "undefined") {
                        setTimeout(goToBillplz, 500);
                    } else {
                        jQuery(document).on("ready", function () {
                            goToBillplz();
                        });
                    }
                })();</script>';
    }

    public function thankyou_page()
    {
        // Thank you message.
        $thankyou_message = $this->get_option('thankyou_message');

        if ($_GET['billplz']['paid']==='true') {
            echo '<div>Note: Refresh this page if payment status is not reflected yet.</div>';
        }

        if (! empty($thankyou_message)) {
            echo '<div class="edr-gateway-description">' . wpautop(stripslashes($thankyou_message)) . '</div>';
        }
    }

    public function process_callback()
    {
        $api_key = trim($this->get_option('api_key'));
        $x_signature = trim($this->get_option('x_signature'));

        try {
            $data = EducatorBillplzWPConnect::getXSignature($x_signature);
        } catch (Exception $e) {
            error_log($e->getMessage());
            exit('Invalid X Signature Calculation');
        }

        $connnect = (new EducatorBillplzWPConnect($api_key))->detectMode();
        $billplz = new EducatorBillplzAPI($connnect);
        list($rheader, $rbody) = $billplz->toArray($billplz->getBill($data['id']));

        $payment = edr_get_payment($rbody['reference_2']);
        if (! $payment->ID) {
            return;
        }

        if ($rbody['paid']) {
            // Update payment status.
            $payment->payment_status = 'complete';
            $payment->txn_id = $rbody['id'];
            $payment->save();
            // Setup course or membership for the student.
            Edr_Payments::get_instance()->setup_payment_item($payment);
        }
        if ($data['type'] === 'callback') {
            echo $data['type'];
        }
    }

    public function sanitize_admin_options($input)
    {
        foreach ($input as $option_name => $value) {
            switch ($option_name) {
                case 'thankyou_message':
                    $input[ $option_name ] = wp_kses_data($value);
                    break;
            }
        }

        return $input;
    }
}

class EducatorBillplzWPConnect
{
    private $api_key;
    private $x_signature_key;
    private $collection_id;

    private $process; //cURL or GuzzleHttp
    public $is_staging;
    public $url;

    public $header;

    const TIMEOUT = 10; //10 Seconds
    const PRODUCTION_URL = 'https://www.billplz.com/api/';
    const STAGING_URL = 'https://billplz-staging.herokuapp.com/api/';

    public function __construct($api_key)
    {
        $this->api_key = $api_key;

        $this->header = array(
            'Authorization' => 'Basic ' . base64_encode($this->api_key . ':')
        );
    }

    public function setMode($is_staging = false)
    {
        $this->is_staging = $is_staging;
        if ($is_staging) {
            $this->url = self::PRODUCTION_URL;
        } else {
            $this->url = self::STAGING_URL;
        }
    }

    public function detectMode()
    {
        $this->url = self::PRODUCTION_URL;
        $collection = $this->toArray($this->getCollectionIndex());
        if ($collection[0] === 200) {
            $this->is_staging = false;
            return $this;
        }
        $this->url = self::STAGING_URL;
        $collection = $this->toArray($this->getCollectionIndex());
        if ($collection[0] === 200) {
            $this->is_staging = true;
            return $this;
        }
        throw new \Exception('The API Key is not valid. Check your API Key');
    }

    public function getCollectionIndex(array $parameter = array())
    {
        $url = $this->url . 'v4/collections?'.http_build_query($parameter);

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['method'] = 'GET';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function createCollection($title, array $optional = array())
    {
        $url = $this->url . 'v4/collections';

        $title = ['title' => $title];
        $data = array_merge($title, $optional);

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['body'] = http_build_query($data);
        $wp_remote_data['method'] = 'POST';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function createOpenCollection(array $parameter, array $optional = array())
    {
        $url = $this->url . 'v4/open_collections';

        //if (sizeof($parameter) !== sizeof($optional) && !empty($optional)){
        //    throw new \Exception('Optional parameter size is not match with Required parameter');
        //}

        $data = array_merge($parameter, $optional);

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['body'] = http_build_query($data);
        $wp_remote_data['method'] = 'POST';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function getCollectionArray(array $parameter)
    {
        $return_array = array();

        foreach ($parameter as $id) {
            $url = $this->url . 'v4/collections/' . $id;

            $wp_remote_data['headers'] = $this->header;
            $wp_remote_data['method'] = 'GET';

            $response = \wp_remote_post($url, $wp_remote_data);
            $header = $response['response']['code'];
            $body = \wp_remote_retrieve_body($response);

            $return= array($header,$body);
            array_push($return_array, $return);
        }

        return $return_array;
    }

    public function getCollection($id)
    {
        $url = $this->url . 'v4/collections/'.$id;

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['method'] = 'GET';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function getOpenCollectionArray(array $parameter)
    {
        $return_array = array();

        foreach ($parameter as $id) {
            $url = $this->url . 'v4/open_collections/'.$id;

            $wp_remote_data['headers'] = $this->header;
            $wp_remote_data['method'] = 'GET';

            $response = \wp_remote_post($url, $wp_remote_data);
            $header = $response['response']['code'];
            $body = \wp_remote_retrieve_body($response);

            $return = array($header,$body);
            array_push($return_array, $return);
        }

        return $return_array;
    }

    public function getOpenCollection($id)
    {
        $url = $this->url . 'v4/open_collections/'.$id;

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['method'] = 'GET';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function getOpenCollectionIndex(array $parameter = array())
    {
        $url = $this->url . 'v4/open_collections?'.http_build_query($parameter);

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['method'] = 'GET';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function createMPICollectionArray(array $parameter)
    {
        $url = $this->url . 'v4/mass_payment_instruction_collections';

        $return_array = array();

        foreach ($parameter as $title) {
            $data = ['title' => $title];

            $wp_remote_data['headers'] = $this->header;
            $wp_remote_data['body'] = http_build_query($data);
            $wp_remote_data['method'] = 'POST';

            $response = \wp_remote_post($url, $wp_remote_data);
            $header = $response['response']['code'];
            $body = \wp_remote_retrieve_body($response);

            $return= array($header,$body);
            array_push($return_array, $return);
        }

        return $return_array;
    }

    public function createMPICollection($title)
    {
        $url = $this->url . 'v4/mass_payment_instruction_collections';

        $data = ['title' => $title];

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['body'] = http_build_query($data);
        $wp_remote_data['method'] = 'POST';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function getMPICollectionArray(array $parameter)
    {
        $return_array = array();

        foreach ($parameter as $id) {
            $url = $this->url . 'v4/mass_payment_instruction_collections/'.$id;

            $wp_remote_data['headers'] = $this->header;
            $wp_remote_data['method'] = 'GET';

            $response = \wp_remote_post($url, $wp_remote_data);
            $header = $response['response']['code'];
            $body = \wp_remote_retrieve_body($response);

            $return= array($header,$body);
            array_push($return_array, $return);
        }

        return $return_array;
    }

    public function getMPICollection($id)
    {
        $url = $this->url . 'v4/mass_payment_instruction_collections/'.$id;

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['method'] = 'GET';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function createMPI(array $parameter, array $optional = array())
    {
        $url = $this->url . 'v4/mass_payment_instructions';

        //if (sizeof($parameter) !== sizeof($optional) && !empty($optional)){
        //    throw new \Exception('Optional parameter size is not match with Required parameter');
        //}

        $data = array_merge($parameter, $optional);

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['body'] = http_build_query($data);
        $wp_remote_data['method'] = 'POST';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function getMPIArray(array $parameter)
    {
        $return_array = array();

        foreach ($parameter as $id) {
            $url = $this->url . 'v4/mass_payment_instructions/'.$id;

            $wp_remote_data['headers'] = $this->header;
            $wp_remote_data['method'] = 'GET';

            $response = \wp_remote_post($url, $wp_remote_data);
            $header = $response['response']['code'];
            $body = \wp_remote_retrieve_body($response);

            $return= array($header,$body);
            array_push($return_array, $return);
        }

        return $return_array;
    }

    public function getMPI($id)
    {
        $url = $this->url . 'v4/mass_payment_instructions/'.$id;

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['body'] = http_build_query($data);
        $wp_remote_data['method'] = 'POST';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public static function getXSignature($x_signature_key)
    {
        $signingString = '';

        if (isset($_GET['billplz']['id']) &&isset($_GET['billplz']['paid_at']) && isset($_GET['billplz']['paid']) && isset($_GET['billplz']['x_signature'])) {
            $data = array(
                'id' => $_GET['billplz']['id'] ,
                'paid_at' =>  $_GET['billplz']['paid_at'],
                'paid' => $_GET['billplz']['paid'],
                'x_signature' =>  $_GET['billplz']['x_signature']
            );
            $type = 'redirect';
        } elseif (isset($_POST['x_signature'])) {
            $data = array(
               'amount' => isset($_POST['amount']) ? $_POST['amount'] : '',
               'collection_id' => isset($_POST['collection_id']) ? $_POST['collection_id'] : '',
               'due_at' => isset($_POST['due_at']) ? $_POST['due_at'] : '',
               'email' => isset($_POST['email']) ? $_POST['email'] : '',
               'id' => isset($_POST['id']) ? $_POST['id'] : '',
               'mobile' => isset($_POST['mobile']) ? $_POST['mobile'] : '',
               'name' => isset($_POST['name']) ? $_POST['name'] : '',
               'paid_amount' => isset($_POST['paid_amount']) ? $_POST['paid_amount'] : '',
               'paid_at' => isset($_POST['paid_at']) ? $_POST['paid_at'] : '',
               'paid' => isset($_POST['paid']) ? $_POST['paid'] : '',
               'state' => isset($_POST['state']) ? $_POST['state'] : '',
               'url' => isset($_POST['url']) ? $_POST['url'] : '',
               'x_signature' => isset($_POST['x_signature']) ? $_POST['x_signature'] :'',
            );
            $type = 'callback';
        } else {
            return false;
        }

        foreach ($data as $key => $value) {
            if (isset($_GET['billplz']['id'])) {
                $signingString .= 'billplz'.$key . $value;
            } else {
                $signingString .= $key . $value;
            }
            if (($key === 'url' && isset($_POST['x_signature']))|| ($key === 'paid' && isset($_GET['billplz']['id']))) {
                break;
            } else {
                $signingString .= '|';
            }
        }

        /*
         * Convert paid status to boolean
         */
        $data['paid'] = $data['paid'] === 'true' ? true : false;

        $signedString = hash_hmac('sha256', $signingString, $x_signature_key);

        if ($data['x_signature'] === $signedString) {
            $data['type'] = $type;
            return $data;
        }

        throw new \Exception('X Signature Calculation Mismatch!');
    }

    public function deactivateColletionArray(array $parameter, $option = 'deactivate')
    {
        $return_array = array();

        foreach ($parameter as $title) {
            $url = $this->url . 'v3/collections/'.$title.'/'.$option;

            $wp_remote_data['headers'] = $this->header;
            $wp_remote_data['body'] = http_build_query(array());
            $wp_remote_data['method'] = 'POST';

            $response = \wp_remote_post($url, $wp_remote_data);
            $header = $response['response']['code'];
            $body = \wp_remote_retrieve_body($response);

            $return =array($header,$body);
            array_push($return_array, $return);
        }

        return $return_array;
    }

    public function deactivateCollection($title, $option = 'deactivate')
    {
        $url = $this->url . 'v3/collections/'.$title.'/'.$option;

        $data = ['title' => $title];

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['body'] = http_build_query(array());
        $wp_remote_data['method'] = 'POST';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function createBill(array $parameter, array $optional = array())
    {
        $url = $this->url . 'v3/bills';

        //if (sizeof($parameter) !== sizeof($optional) && !empty($optional)){
        //    throw new \Exception('Optional parameter size is not match with Required parameter');
        //}

        $data = array_merge($parameter, $optional);

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['body'] = http_build_query($data);
        $wp_remote_data['method'] = 'POST';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function getBillArray(array $parameter)
    {
        $return_array = array();

        foreach ($parameter as $id) {
            $url = $this->url . 'v3/bills/'.$id;

            $wp_remote_data['headers'] = $this->header;
            $wp_remote_data['method'] = 'GET';

            $response = \wp_remote_post($url, $wp_remote_data);
            $header = $response['response']['code'];
            $body = \wp_remote_retrieve_body($response);

            $return = array($header,$body);
            array_push($return_array, $return);
        }

        return $return_array;
    }

    public function getBill($id)
    {
        $url = $this->url . 'v3/bills/'.$id;

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['method'] = 'GET';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function deleteBillArray(array $parameter)
    {
        $return_array = array();

        foreach ($parameter as $id) {
            $url = $this->url . 'v3/bills/'.$id;

            $wp_remote_data['headers'] = $this->header;
            $wp_remote_data['body'] = http_build_query(array());
            $wp_remote_data['method'] = 'DELETE';

            $response = \wp_remote_post($url, $wp_remote_data);
            $header = $response['response']['code'];
            $body = \wp_remote_retrieve_body($response);

            $return= array($header,$body);
            array_push($return_array, $return);
        }

        return $return_array;
    }

    public function deleteBill($id)
    {
        $url = $this->url . 'v3/bills/'.$id;

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['body'] = http_build_query(array());
        $wp_remote_data['method'] = 'DELETE';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function bankAccountCheckArray(array $parameter)
    {
        $return_array = array();

        foreach ($parameter as $id) {
            $url = $this->url . 'v3/check/bank_account_number/'.$id;

            $wp_remote_data['headers'] = $this->header;
            $wp_remote_data['method'] = 'GET';

            $response = \wp_remote_post($url, $wp_remote_data);
            $header = $response['response']['code'];
            $body = \wp_remote_retrieve_body($response);

            $return = array($header,$body);
            array_push($return_array, $return);
        }

        return $return_array;
    }

    public function bankAccountCheck($id)
    {
        $url = $this->url . 'v3/check/bank_account_number/'.$id;

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['method'] = 'GET';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function getPaymentMethodIndexArray(array $parameter)
    {
        $return_array = array();

        foreach ($parameter as $id) {
            $url = $this->url . 'v3/collections/'.$id.'/payment_methods';

            $wp_remote_data['headers'] = $this->header;
            $wp_remote_data['method'] = 'GET';

            $response = \wp_remote_post($url, $wp_remote_data);
            $header = $response['response']['code'];
            $body = \wp_remote_retrieve_body($response);

            $return =array($header,$body);
            array_push($return_array, $return);
        }

        return $return_array;
    }

    public function getPaymentMethodIndex($id)
    {
        $url = $this->url . 'v3/collections/'.$id.'/payment_methods';

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['method'] = 'GET';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function getTransactionIndex($id, array $parameter)
    {
        $url = $this->url . 'v3/bills/'.$id.'/transactions?'.http_build_query($parameter);

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['method'] = 'GET';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function updatePaymentMethod(array $parameter)
    {
        if (!isset($parameter['collection_id'])) {
            throw new \Exception('Collection ID is not passed on updatePaymethodMethod');
        }
        $url = $this->url . 'v3/collections/'.$parameter['collection_id'].'/payment_methods';

        unset($parameter['collection_id']);
        $data = $parameter;

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['body'] = http_build_query($data);
        $wp_remote_data['method'] = 'PUT';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function getBankAccountIndex(array $parameter)
    {
        if (!is_array($parameter['account_numbers'])) {
            throw new \Exception('Not valid account numbers.');
        }

        $parameter = http_build_query($parameter);
        $parameter = preg_replace('/%5B[0-9]+%5D/simU', '%5B%5D', $parameter);

        $url = $this->url . 'v3/bank_verification_services?'.$parameter;

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['method'] = 'GET';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function getBankAccountArray(array $parameter)
    {
        $return_array = array();

        foreach ($parameter as $id) {
            $url = $this->url . 'v3/bank_verification_services/'.$id;

            $wp_remote_data['headers'] = $this->header;
            $wp_remote_data['method'] = 'GET';

            $response = \wp_remote_post($url, $wp_remote_data);
            $header = $response['response']['code'];
            $body = \wp_remote_retrieve_body($response);

            $return= array($header,$body);
            array_push($return_array, $return);
        }

        return $return_array;
    }

    public function getBankAccount($id)
    {
        $url = $this->url . 'v3/bank_verification_services/'.$id;

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['method'] = 'GET';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function createBankAccount(array $parameter)
    {
        $url = $this->url . 'v3/bank_verification_services';

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['body'] = http_build_query($data);
        $wp_remote_data['method'] = 'POST';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function getFpxBanks()
    {
        $url = $this->url . 'v3/fpx_banks';

        $wp_remote_data['headers'] = $this->header;
        $wp_remote_data['method'] = 'GET';

        $response = \wp_remote_post($url, $wp_remote_data);
        $header = $response['response']['code'];
        $body = \wp_remote_retrieve_body($response);

        return array($header,$body);
    }

    public function closeConnection()
    {
    }

    public function toArray(array $json)
    {
        return array($json[0], \json_decode($json[1], true));
    }
}

class EducatorBillplzAPI
{
    private $connect;

    public function __construct(EducatorBillplzWPConnect $connect)
    {
        $this->connect = $connect;
    }

    public function setConnect(\Billplz\Connect $connect)
    {
        $this->connect = $connect;
    }

    public function getCollectionIndex(array $parameter = array())
    {
        return $this->connect->getCollectionIndex($parameter);
    }

    public function createCollection(string $parameter, array $optional = array())
    {
        return $this->connect->createCollection($parameter, $optional);
    }

    public function getCollection($parameter)
    {
        if (\is_array($parameter)) {
            return $this->connect->getCollectionArray($parameter);
        }
        if (\is_string($parameter)) {
            return $this->connect->getCollection($parameter);
        }

        throw new \Exception('Get Collection Error!');
    }

    public function createOpenCollection(array $parameter, array $optional = array())
    {
        $parameter['title'] = substr($parameter['title'], 0, 49);
        $parameter['description'] = substr($parameter['description'], 0, 199);

        if (intval($parameter['amount']) > 999999999) {
            throw new \Exception("Amount Invalid. Too big");
        }

        return $this->connect->createOpenCollection($parameter, $optional);
    }

    public function getOpenCollection($parameter)
    {
        if (\is_array($parameter)) {
            return $this->connect->getOpenCollectionArray($parameter);
        }
        if (\is_string($parameter)) {
            return $this->connect->getOpenCollection($parameter);
        }

        throw new \Exception('Get Open Collection Error!');
    }

    public function getOpenCollectionIndex(array $parameter = array())
    {
        return $this->connect->getOpenCollectionIndex($parameter);
    }

    public function createMPICollection($parameter)
    {
        if (\is_array($parameter)) {
            return $this->connect->createMPICollectionArray($parameter);
        }
        if (\is_string($parameter)) {
            return $this->connect->createMPICollection($parameter);
        }

        throw new \Exception('Create MPI Collection Error!');
    }

    public function getMPICollection($parameter)
    {
        if (\is_array($parameter)) {
            return $this->connect->getMPICollectionArray($parameter);
        }
        if (\is_string($parameter)) {
            return $this->connect->getMPICollection($parameter);
        }

        throw new \Exception('Get MPI Collection Error!');
    }

    public function createMPI(array $parameter, array $optional = array())
    {
        return $this->connect->createMPI($parameter, $optional);
    }

    public function getMPI($parameter)
    {
        if (\is_array($parameter)) {
            return $this->connect->getMPIArray($parameter);
        }
        if (\is_string($parameter)) {
            return $this->connect->getMPI($parameter);
        }

        throw new \Exception('Get MPI Error!');
    }

    public function deactivateCollection($parameter)
    {
        if (\is_array($parameter)) {
            return $this->connect->deactivateColletionArray($parameter);
        }
        if (\is_string($parameter)) {
            return $this->connect->deactivateCollection($parameter);
        }

        throw new \Exception('Deactivate Collection Error!');
    }

    public function activateCollection($parameter)
    {
        if (\is_array($parameter)) {
            return $this->connect->deactivateColletionArray($parameter, 'activate');
        }
        if (\is_string($parameter)) {
            return $this->connect->deactivateCollection($parameter, 'activate');
        }

        throw new \Exception('Activate Collection Error!');
    }

    public function createBill(array $parameter, array $optional = array(), $sendCopy = '')
    {

        /* Email or Mobile must be set */
        if (empty($parameter['email']) && empty($parameter['mobile'])) {
            throw new \Exception("Email or Mobile must be set!");
        }

        /* Manipulate Deliver features to allow Email/SMS Only copy */
        if ($sendCopy === '0') {
            $optioonal['deliver'] = 'false';
        } elseif ($sendCopy === '1' && !empty($parameter['email'])) {
            $optional['deliver'] = 'true';
            unset($parameter['mobile']);
        } elseif ($sendCopy === '2' && !empty($parameter['mobile'])) {
            $optional['deliver'] = 'true';
            unset($parameter['email']);
        } elseif ($sendCopy === '3') {
            $optional['deliver'] = 'true';
        }

        /* Validate Mobile Number first */
        if (!empty($parameter['mobile'])) {
            /* Strip all unwanted character */
            $parameter['mobile'] = preg_replace('/[^0-9]/', '', $parameter['mobile']);

            /* Add '6' if applicable */
            $parameter['mobile'] = $parameter['mobile'][0] === '0' ? '6'.$parameter['mobile'] : $parameter['mobile'];

            /* If the number doesn't have valid formatting, reject it */
            /* The ONLY valid format '<1 Number>' + <10 Numbers> or '<1 Number>' + <11 Numbers> */
            /* Example: '60141234567' or '601412345678' */
            if (!preg_match('/^[0-9]{11,12}$/', $parameter['mobile'], $m)) {
                $parameter['mobile'] = '';
            }
        }

        /* Create Bills */
        $bill = $this->connect->createBill($parameter, $optional);
        if ($bill[0] === 200) {
            return $bill;
        }

        /* Check if Failed caused by wrong Collection ID */
        $collection = $this->toArray($this->getCollection($parameter['collection_id']));

        /* If doesn't exists or belong to another merchant */
        /* + In-case the collection id is an empty string */
        if ($collection[0] === 404 || $collection[0] === 401 || empty($parameter['collection_id'])) {
            /* Get All Active & Inactive Collection List */
            $collectionIndexActive = $this->toArray($this->getCollectionIndex(array('page'=>'1', 'status'=>'active')));
            $collectionIndexInactive = $this->toArray($this->getCollectionIndex(array('page'=>'1', 'status'=>'inactive')));

            /* If Active Collection not available but Inactive Collection is available */
            if (empty($collectionIndexActive[1]['collections']) && !empty($collectionIndexInactive[1]['collections'])) {
                /* Use inactive collection */
                $parameter['collection_id'] = $collectionIndexInactive[1]['collections'][0]['id'];
            }

            /* If there is Active Collection */
            elseif (!empty($collectionIndexActive[1]['collections'])) {
                $parameter['collection_id'] = $collectionIndexActive[1]['collections'][0]['id'];
            }

            /* If there is no Active and Inactive Collection */
            else {
                $collection = $this->toArray($this->createCollection('Payment for Purchase'));
                $parameter['collection_id'] = $collection[1]['id'];
            }
        }

        /* Create Bills */
        return $this->connect->createBill($parameter, $optional);
    }

    public function deleteBill($parameter)
    {
        if (\is_array($parameter)) {
            return $this->connect->deleteBillArray($parameter);
        }
        if (\is_string($parameter)) {
            return $this->connect->deleteBill($parameter);
        }

        throw new \Exception('Delete Bill Error!');
    }

    public function getBill($parameter)
    {
        if (\is_array($parameter)) {
            return $this->connect->getBillArray($parameter);
        }
        if (\is_string($parameter)) {
            return $this->connect->getBill($parameter);
        }

        throw new \Exception('Get Bill Error!');
    }

    public function bankAccountCheck($parameter)
    {
        if (\is_array($parameter)) {
            return $this->connect->bankAccountCheckArray($parameter);
        }
        if (\is_string($parameter)) {
            return $this->connect->bankAccountCheck($parameter);
        }

        throw new \Exception('Registration Check by Account Number Error!');
    }

    public function getTransactionIndex(string $id, array $parameter = array('page'=>'1'))
    {
        return $this->connect->getTransactionIndex($id, $parameter);
    }

    public function getPaymentMethodIndex($parameter)
    {
        if (\is_array($parameter)) {
            return $this->connect->getPaymentMethodIndexArray($parameter);
        }
        if (\is_string($parameter)) {
            return $this->connect->getPaymentMethodIndex($parameter);
        }

        throw new \Exception('Get Payment Method Index Error!');
    }

    public function updatePaymentMethod(array $parameter)
    {
        return $this->connect->updatePaymentMethod($parameter);
    }

    public function getBankAccountIndex(array $parameter = array('account_numbers'=>['0','1']))
    {
        return $this->connect->getBankAccountIndex($parameter);
    }

    public function getBankAccount($parameter)
    {
        if (\is_array($parameter)) {
            return $this->connect->getBankAccountArray($parameter);
        }
        if (\is_string($parameter)) {
            return $this->connect->getBankAccount($parameter);
        }

        throw new \Exception('Get Bank Account Error!');
    }

    public function createBankAccount(array $parameter)
    {
        return $this->connect->createBankAccount($parameter);
    }

    public function bypassBillplzPage(string $bill)
    {
        $bills = \json_decode($bill, true);
        if ($bills['reference_1_label']!=='Bank Code') {
            return \json_encode($bill);
        }

        $fpxBanks = $this->toArray($this->getFpxBanks());
        if ($fpxBanks[0] !== 200) {
            return \json_encode($bill);
        }

        $found = false;
        foreach ($fpxBanks[1]['banks'] as $bank) {
            if ($bank['name'] === $bills['reference_1']) {
                if ($bank['active']) {
                    $found = true;
                    break;
                }
                return \json_encode($bill);
            }
        }

        if ($found) {
            $bills['url'].='?auto_submit=true';
        }

        return json_encode($bills);
    }

    public function getFpxBanks()
    {
        return $this->connect->getFpxBanks();
    }

    public function toArray(array $json)
    {
        return $this->connect->toArray($json);
    }
}
