<?php
if(!defined('ABSPATH')) {die('You are not allowed to call this page directly.');}

class MeprStripeGateway extends MeprBaseRealGateway {
  public static $stripe_plan_id_str = '_mepr_stripe_plan_id';

  /** Used in the view to identify the gateway */
  public function __construct() {
    $this->name = __("Stripe", 'memberpress');
    $this->icon = MEPR_IMAGES_URL . '/checkout/cards.png';
    $this->desc = __('Pay with your credit card via Stripe', 'memberpress');
    $this->set_defaults();

    $this->capabilities = array(
      'process-credit-cards',
      'process-payments',
      'process-refunds',
      'create-subscriptions',
      'cancel-subscriptions',
      'update-subscriptions',
      'suspend-subscriptions',
      'resume-subscriptions',
      'send-cc-expirations'
    );

    // Setup the notification actions for this gateway
    $this->notifiers = array( 'whk' => 'listener' );
  }

  public function load($settings) {
    $this->settings = (object)$settings;
    $this->set_defaults();
  }

  protected function set_defaults() {
    if(!isset($this->settings)) {
      $this->settings = array();
    }

    $this->settings = (object)array_merge(
      array(
        'gateway' => 'MeprStripeGateway',
        'id' => $this->generate_id(),
        'label' => '',
        'use_label' => true,
        'use_icon' => true,
        'use_desc' => true,
        'email' => '',
        'sandbox' => false,
        'force_ssl' => false,
        'debug' => false,
        'test_mode' => false,
        'api_keys' => array(
          'test' => array(
            'public' => '',
            'secret' => ''
          ),
          'live' => array(
            'public' => '',
            'secret' => ''
          )
        )
      ),
      (array)$this->settings
    );

    $this->id = $this->settings->id;
    $this->label = $this->settings->label;
    $this->use_label = $this->settings->use_label;
    $this->use_icon = $this->settings->use_icon;
    $this->use_desc = $this->settings->use_desc;
    //$this->recurrence_type = $this->settings->recurrence_type;

    if($this->is_test_mode()) {
      $this->settings->public_key = $this->settings->api_keys['test']['public'];
      $this->settings->secret_key = $this->settings->api_keys['test']['secret'];
    }
    else {
      $this->settings->public_key = $this->settings->api_keys['live']['public'];
      $this->settings->secret_key = $this->settings->api_keys['live']['secret'];
    }

    // An attempt to correct people who paste in spaces along with their credentials
    $this->settings->api_keys['test']['secret'] = trim($this->settings->api_keys['test']['secret']);
    $this->settings->api_keys['test']['public'] = trim($this->settings->api_keys['test']['public']);
    $this->settings->api_keys['live']['secret'] = trim($this->settings->api_keys['live']['secret']);
    $this->settings->api_keys['live']['public'] = trim($this->settings->api_keys['live']['public']);
  }

  /** Used to send data to a given payment gateway. In gateways which redirect
    * before this step is necessary this method should just be left blank.
    */
  public function process_payment($txn) {
    if(isset($txn) and $txn instanceof MeprTransaction) {
      $usr = $txn->user();
      $prd = $txn->product();
    }
    else {
      throw new MeprGatewayException( __('Payment was unsuccessful, please check your payment details and try again.', 'memberpress') );
    }

    $mepr_options = MeprOptions::fetch();

    //Handle zero decimal currencies in Stripe
    $amount = (MeprUtils::is_zero_decimal_currency())?MeprUtils::format_float(($txn->total), 0):MeprUtils::format_float(($txn->total * 100), 0);

    // create the charge on Stripe's servers - this will charge the user's card
    $args = MeprHooks::apply_filters('mepr_stripe_payment_args', array(
      'amount' => $amount,
      'currency' => $mepr_options->currency_code,
      'description' => sprintf(__('%s (transaction: %s)', 'memberpress'), $prd->post_title, $txn->id ),
      'metadata' => array(
        'ip_address' => $_SERVER['REMOTE_ADDR']
      )
    ), $txn);

    // get the credit card details submitted by the form
    if(isset($_REQUEST['stripe_token'])) {
      $args['card'] = $_REQUEST['stripe_token'];
    }
    else if(isset($_REQUEST['stripe_customer'])) {
      $args['customer'] = $_REQUEST['stripe_customer'];
    }
    else if(isset($_REQUEST['mepr_cc_num'])) {
      $args['card'] = array(
        'number'    => $_REQUEST['mepr_cc_num'],
        'exp_month' => $_REQUEST['mepr_cc_exp_month'],
        'exp_year'  => $_REQUEST['mepr_cc_exp_year'],
        'cvc'       => $_REQUEST['mepr_cvv_code']
      );
    }
    else {
      ob_start();
      print_r($_REQUEST);
      $err = ob_get_clean();
      throw new MeprGatewayException( __('There was a problem sending your credit card details to the processor. Please try again later.' , 'memberpress') . ' 1 ' . $err );
    }

    $usr = $txn->user();

    $this->email_status('Stripe Charge Happening Now ... ' . MeprUtils::object_to_string($args), $this->settings->debug);

    $charge = (object)$this->send_stripe_request( 'charges', $args, 'post' );
    $this->email_status('Stripe Charge: ' . MeprUtils::object_to_string($charge), $this->settings->debug);

    $txn->trans_num = $charge->id;
    $txn->response = json_encode($charge);
    $txn->store();

    $this->email_status('Stripe Charge Happening Now ... 2', $this->settings->debug);

    $_REQUEST['data'] = $charge;

    return $this->record_payment();
  }

  /** Used to record a successful recurring payment by the given gateway. It
    * should have the ability to record a successful payment or a failure. It is
    * this method that should be used when receiving an IPN from PayPal or a
    * Silent Post from Authorize.net.
    */
  public function record_subscription_payment() {
    if(isset($_REQUEST['data'])) {
      $charge = (object)$_REQUEST['data'];

      // Make sure there's a valid subscription for this request and this payment hasn't already been recorded
      if( !isset($charge) or !isset($charge->customer) or
          !($sub = MeprSubscription::get_one_by_subscr_id($charge->customer)) or
          ( isset($charge->id) and MeprTransaction::txn_exists($charge->id) ) ) {
        return false;
      }

      $first_txn = $txn = $sub->first_txn();

      $this->email_status( "record_subscription_payment:" .
                           "\nSubscription: " . MeprUtils::object_to_string($sub, true) .
                           "\nTransaction: " . MeprUtils::object_to_string($txn, true),
                           $this->settings->debug);

      $txn = new MeprTransaction();
      $txn->user_id    = $sub->user_id;
      $txn->product_id = $sub->product_id;
      $txn->status     = MeprTransaction::$complete_str;
      $txn->coupon_id  = $first_txn->coupon_id;
      $txn->response   = json_encode($charge);
      $txn->trans_num  = $charge->id;
      $txn->gateway    = $this->id;
      $txn->subscription_id = $sub->ID;

      if(MeprUtils::is_zero_decimal_currency()) {
        $txn->set_gross((float)$charge->amount);
      }
      else {
        $txn->set_gross((float)$charge->amount / 100);
      }

      $sdata = $this->send_stripe_request("customers/{$sub->subscr_id}", array(), 'get');

      // 'subscription' attribute went away in 2014-01-31
      //$txn->expires_at = MeprUtils::ts_to_mysql_date($sdata['subscription']['current_period_end'], 'Y-m-d 23:59:59');

      $this->email_status( "/customers/{$sub->subscr_id}\n" .
                           MeprUtils::object_to_string($sdata, true) .
                           MeprUtils::object_to_string($txn, true),
                           $this->settings->debug );

      $txn->store();

      $sub->status = MeprSubscription::$active_str;

      if($card = $this->get_card($charge)) {
        $sub->cc_exp_month = $card['exp_month'];
        $sub->cc_exp_year  = $card['exp_year'];
        $sub->cc_last4     = $card['last4'];
      }

      $sub->gateway = $this->id;
      $sub->store();
      // If a limit was set on the recurring cycles we need
      // to cancel the subscr if the txn_count >= limit_cycles_num
      // This is not possible natively with Stripe so we
      // just cancel the subscr when limit_cycles_num is hit
      $sub->limit_payment_cycles();

      $this->email_status( "Subscription Transaction\n" .
                           MeprUtils::object_to_string($txn->rec, true),
                           $this->settings->debug );

      $this->send_transaction_receipt_notices( $txn );
      $this->send_cc_expiration_notices( $txn );

      return $txn;
    }

    return false;
  }

  /** Used to record a declined payment. */
  public function record_payment_failure() {
    if(isset($_REQUEST['data']))
    {
      $charge = (object)$_REQUEST['data'];
      $txn_res = MeprTransaction::get_one_by_trans_num($charge->id);
      if(is_object($txn_res) and isset($txn_res->id)) {
        $txn = new MeprTransaction($txn_res->id);
        $txn->status = MeprTransaction::$failed_str;
        $txn->store();
      }
      else if( isset($charge) and isset($charge->customer) and
               $sub = MeprSubscription::get_one_by_subscr_id($charge->customer) ) {
        $first_txn = $sub->first_txn();
        $latest_txn = $sub->latest_txn();

        $txn = new MeprTransaction();
        $txn->user_id = $sub->user_id;
        $txn->product_id = $sub->product_id;
        $txn->coupon_id = $first_txn->coupon_id;
        $txn->txn_type = MeprTransaction::$payment_str;
        $txn->status = MeprTransaction::$failed_str;
        $txn->subscription_id = $sub->ID;
        $txn->response = json_encode($_REQUEST);
        $txn->trans_num = $charge->id;
        $txn->gateway = $this->id;

        if(MeprUtils::is_zero_decimal_currency()) {
          $txn->set_gross((float)$charge->amount);
        }
        else {
          $txn->set_gross((float)$charge->amount / 100);
        }

        $txn->store();

        $sub->status = MeprSubscription::$active_str;
        $sub->gateway = $this->id;
        $sub->expire_txns(); //Expire associated transactions for the old subscription
        $sub->store();
      }
      else
        return false; // Nothing we can do here ... so we outta here

      $this->send_failed_txn_notices($txn);

      return $txn;
    }

    return false;
  }

  /** Used to record a successful payment by the given gateway. It should have
    * the ability to record a successful payment or a failure. It is this method
    * that should be used when receiving an IPN from PayPal or a Silent Post
    * from Authorize.net.
    */
  public function record_payment() {
    $this->email_status( "Starting record_payment: " . MeprUtils::object_to_string($_REQUEST), $this->settings->debug );
    if(isset($_REQUEST['data'])) {
      $charge = (object)$_REQUEST['data'];
      $this->email_status("record_payment: \n" . MeprUtils::object_to_string($charge, true) . "\n", $this->settings->debug);
      $obj = MeprTransaction::get_one_by_trans_num($charge->id);

      if(is_object($obj) and isset($obj->id)) {
        $txn = new MeprTransaction();
        $txn->load_data($obj);
        $usr = $txn->user();

        // Just short circuit if the txn has already completed
        if($txn->status == MeprTransaction::$complete_str)
          return;

        $txn->status    = MeprTransaction::$complete_str;
        $txn->response  = json_encode($charge);

        // This will only work before maybe_cancel_old_sub is run
        $upgrade = $txn->is_upgrade();
        $downgrade = $txn->is_downgrade();

        $txn->maybe_cancel_old_sub();
        $txn->store();

        $this->email_status("Standard Transaction\n" . MeprUtils::object_to_string($txn->rec, true) . "\n", $this->settings->debug);

        $prd = $txn->product();

        if( $prd->period_type=='lifetime' ) {
          if( $upgrade ) {
            $this->upgraded_sub($txn);
            $this->send_upgraded_txn_notices( $txn );
          }
          else if( $downgrade ) {
            $this->downgraded_sub($txn);
            $this->send_downgraded_txn_notices( $txn );
          }
          else {
            $this->new_sub($txn);
          }

          $this->send_product_welcome_notices($txn);
          $this->send_signup_notices( $txn );
        }

        $this->send_transaction_receipt_notices( $txn );
        $this->send_cc_expiration_notices( $txn );
      }
    }

    return false;
  }

  /** This method should be used by the class to record a successful refund from
    * the gateway. This method should also be used by any IPN requests or Silent Posts.
    */
  public function process_refund(MeprTransaction $txn) {
    $args = MeprHooks::apply_filters('mepr_stripe_refund_args', array(), $txn);
    $refund = (object)$this->send_stripe_request( "charges/{$txn->trans_num}/refund", $args );
    $this->email_status( "Stripe Refund: " . MeprUtils::object_to_string($refund), $this->settings->debug );
    $_REQUEST['data'] = $refund;
    return $this->record_refund();
  }

  /** This method should be used by the class to record a successful refund from
    * the gateway. This method should also be used by any IPN requests or Silent Posts.
    */
  public function record_refund() {
    if(isset($_REQUEST['data']))
    {
      $charge = (object)$_REQUEST['data'];
      $obj = MeprTransaction::get_one_by_trans_num($charge->id);

      if(!is_null($obj) && (int)$obj->id > 0) {
        $txn = new MeprTransaction($obj->id);

        // Seriously ... if txn was already refunded what are we doing here?
        if($txn->status == MeprTransaction::$refunded_str) { return $txn->id; }

        $txn->status = MeprTransaction::$refunded_str;
        $txn->store();

        $this->send_refunded_txn_notices($txn);

        return $txn->id;
      }
    }

    return false;
  }

  public function process_trial_payment($txn) {
    $mepr_options = MeprOptions::fetch();
    $sub = $txn->subscription();

    // get the credit card details submitted by the form
    if(isset($_REQUEST['stripe_token']))
      $card = $_REQUEST['stripe_token'];
    elseif(isset($_REQUEST['mepr_cc_num'])) {
      $card = array( 'number'    => $_REQUEST['mepr_cc_num'],
                     'exp_month' => $_REQUEST['mepr_cc_exp_month'],
                     'exp_year'  => $_REQUEST['mepr_cc_exp_year'],
                     'cvc'       => $_REQUEST['mepr_cvv_code'] );
    }
    else {
      throw new MeprGatewayException( __('There was a problem sending your credit card details to the processor. Please try again later.', 'memberpress') );
    }

    $customer = $this->stripe_customer($txn->subscription_id, $card);

    //Prepare the $txn for the process_payment method
    $txn->set_subtotal($sub->trial_amount);
    $txn->status = MeprTransaction::$pending_str;

    unset($_REQUEST['stripe_token']);
    $_REQUEST['stripe_customer'] = $customer->id;

    //Attempt processing the payment here - the send_aim_request will throw the exceptions for us
    $this->process_payment($txn);

    return $this->record_trial_payment($txn);
  }

  public function record_trial_payment($txn) {
    $sub = $txn->subscription();

    //Update the txn member vars and store
    $txn->txn_type = MeprTransaction::$payment_str;
    $txn->status = MeprTransaction::$complete_str;
    $txn->expires_at = MeprUtils::ts_to_mysql_date(time() + MeprUtils::days($sub->trial_days), 'Y-m-d 23:59:59');
    $txn->store();

    return true;
  }

  /** Used to send subscription data to a given payment gateway. In gateways
    * which redirect before this step is necessary this method should just be
    * left blank.
    */
  public function process_create_subscription($txn) {
    if(isset($txn) and $txn instanceof MeprTransaction) {
      $usr = $txn->user();
      $prd = $txn->product();
    }
    else {
      throw new MeprGatewayException( __('Payment was unsuccessful, please check your payment details and try again.', 'memberpress') );
    }

    $mepr_options = MeprOptions::fetch();
    $sub = $txn->subscription();

    //Get the customer -- if the $sub had a paid trial, then the customer was already setup
    if($sub->trial && $sub->trial_amount > 0.00) {
      $customer = $this->stripe_customer($txn->subscription_id);
    }
    else {
      // get the credit card details submitted by the form
      if(isset($_REQUEST['stripe_token'])) {
        $card = $_REQUEST['stripe_token'];
      }
      elseif(isset($_REQUEST['mepr_cc_num'])) {
        $card = array( 'number'    => $_REQUEST['mepr_cc_num'],
                       'exp_month' => $_REQUEST['mepr_cc_exp_month'],
                       'exp_year'  => $_REQUEST['mepr_cc_exp_year'],
                       'cvc'       => $_REQUEST['mepr_cvv_code'] );
      }
      else {
        throw new MeprGatewayException( __('There was a problem sending your credit card details to the processor. Please try again later.', 'memberpress') );
      }

      $customer = $this->stripe_customer($txn->subscription_id, $card);
    }

    $plan = $this->stripe_plan($txn->subscription(), true);

    $args = MeprHooks::apply_filters('mepr_stripe_subscription_args', array(
      'plan' => $plan->id,
      'metadata' => array(
        'ip_address' => $_SERVER['REMOTE_ADDR']
      ),
      'tax_percent' => MeprUtils::format_float($txn->tax_rate)
    ), $txn, $sub);

    $this->email_status("process_create_subscription: \n" . MeprUtils::object_to_string($txn, true) . "\n", $this->settings->debug);

    $subscr = $this->send_stripe_request( "customers/{$customer->id}/subscriptions", $args, 'post' );
    $sub->subscr_id = $customer->id;
    $sub->store();

    $_REQUEST['data'] = $customer;

    return $this->record_create_subscription();
  }

  /** Used to record a successful subscription by the given gateway. It should have
    * the ability to record a successful subscription or a failure. It is this method
    * that should be used when receiving an IPN from PayPal or a Silent Post
    * from Authorize.net.
    */
  public function record_create_subscription() {
    $mepr_options = MeprOptions::fetch();

    if(isset($_REQUEST['data'])) {
      $sdata = (object)$_REQUEST['data'];
      $sub = MeprSubscription::get_one_by_subscr_id($sdata->id);
      $sub->response=$sdata;
      $sub->status=MeprSubscription::$active_str;

      if($card = $this->get_default_card($sdata)) {
        $sub->cc_last4 = $card['last4'];
        $sub->cc_exp_month = $card['exp_month'];
        $sub->cc_exp_year = $card['exp_year'];
      }

      $sub->created_at = date('c');
      $sub->store();

      // This will only work before maybe_cancel_old_sub is run
      $upgrade = $sub->is_upgrade();
      $downgrade = $sub->is_downgrade();

      $sub->maybe_cancel_old_sub();

      $txn = $sub->first_txn();
      $old_total = $txn->total;

      // If no trial or trial amount is zero then we've got to make
      // sure the confirmation txn lasts through the trial
      if(!$sub->trial || ($sub->trial and $sub->trial_amount <= 0.00)) {
        $trial_days = ($sub->trial)?$sub->trial_days:$mepr_options->grace_init_days;

        $txn->trans_num  = $sub->subscr_id;
        $txn->status     = MeprTransaction::$confirmed_str;
        $txn->txn_type   = MeprTransaction::$subscription_confirmation_str;
        $txn->response   = (string)$sub;
        $txn->expires_at = MeprUtils::ts_to_mysql_date(time() + MeprUtils::days($trial_days), 'Y-m-d H:i:s');
        $txn->set_subtotal(0.00); // Just a confirmation txn
        $txn->store();
      }

      $txn->set_gross($old_total); // Artificially set the subscription amount

      if($upgrade) {
        $this->upgraded_sub($sub);
        $this->send_upgraded_sub_notices($sub);
      }
      else if($downgrade) {
        $this->downgraded_sub($sub);
        $this->send_downgraded_sub_notices($sub);
      }
      else {
        $this->new_sub($sub);
        $this->send_new_sub_notices($sub);
      }

      $this->send_product_welcome_notices($txn);
      $this->send_signup_notices( $txn );

      return array('subscription' => $sub, 'transaction' => $txn);
    }

    return false;
  }

  public function process_update_subscription($sub_id) {
    $mepr_options = MeprOptions::fetch();
    $sub = new MeprSubscription($sub_id);

    if(!isset($_REQUEST['stripe_token'])) {
      ob_start();
      print_r($_REQUEST);
      $err = ob_get_clean();
      throw new MeprGatewayException( __('There was a problem sending your credit card details to the processor. Please try again later. 1' , 'memberpress') . ' 3 ' . $err );
    }

    // get the credit card details submitted by the form
    $token    = $_REQUEST['stripe_token'];
    $customer = $this->stripe_customer($sub_id, $token);

    $usr = $sub->user();

    $args = MeprHooks::apply_filters('mepr_stripe_update_subscription_args', array("card" => $token), $sub);

    $subscr = (object)$this->send_stripe_request( "customers/{$customer->id}", $args, 'post' );
    $sub->subscr_id = $subscr->id;

    if( $card = $this->get_default_card( $subscr ) ) {
      $sub->cc_last4 = $card['last4'];
      $sub->cc_exp_month = $card['exp_month'];
      $sub->cc_exp_year = $card['exp_year'];
    }

    $sub->response = $subscr;
    $sub->store();

    return $subscr;
  }

  /** This method should be used by the class to record a successful cancellation
    * from the gateway. This method should also be used by any IPN requests or
    * Silent Posts.
    */
  public function record_update_subscription() {
    // No need for this one with stripe
  }

  /** Used to suspend a subscription by the given gateway.
    */
  public function process_suspend_subscription($sub_id) {
    $mepr_options = MeprOptions::fetch();
    $sub = new MeprSubscription($sub_id);

    // If there's not already a customer then we're done here
    if(!($customer = $this->stripe_customer($sub_id))) { return false; }

    $args = MeprHooks::apply_filters('mepr_stripe_suspend_subscription_args', array(), $sub);

    // Yeah ... we're cancelling here bro ... with stripe we should be able to restart again
    $res = $this->send_stripe_request( "customers/{$customer->id}/subscription", $args, 'delete' );
    $_REQUEST['data'] = $res;

    return $this->record_suspend_subscription();
  }

  /** This method should be used by the class to record a successful suspension
    * from the gateway.
    */
  public function record_suspend_subscription() {
    if(isset($_REQUEST['data']))
    {
      $sdata = (object)$_REQUEST['data'];
      if( $sub = MeprSubscription::get_one_by_subscr_id($sdata->customer) ) {
        // Seriously ... if sub was already cancelled what are we doing here?
        if($sub->status == MeprSubscription::$suspended_str) { return $sub; }

        $sub->status = MeprSubscription::$suspended_str;
        $sub->store();

        $this->send_suspended_sub_notices($sub);
      }
    }

    return false;
  }

  /** Used to suspend a subscription by the given gateway.
    */
  public function process_resume_subscription($sub_id) {
    $mepr_options = MeprOptions::fetch();
    $sub = new MeprSubscription($sub_id);

    $customer = $this->stripe_customer($sub_id);

    //Set enough of the $customer data here to get this resumed
    if(empty($customer)) { $customer = (object)array('id' => $sub->subscr_id); }

    $orig_trial        = $sub->trial;
    $orig_trial_days   = $sub->trial_days;
    $orig_trial_amount = $sub->trial_amount;

    if( $sub->is_expired() and !$sub->is_lifetime()) {
      $exptxn = $sub->expiring_txn();

      // if it's already expired with a real transaction
      // then we want to resume immediately
      if($exptxn->status!=MeprTransaction::$confirmed_str) {
        $sub->trial = false;
        $sub->trial_days = 0;
        $sub->trial_amount = 0.00;
        $sub->store();
      }
    }
    else {
      $sub->trial = true;
      $sub->trial_days = MeprUtils::tsdays(strtotime($sub->expires_at) - time());
      $sub->trial_amount = 0.00;
      $sub->store();
    }

    // Create new plan with optional trial in place ...
    $plan = $this->stripe_plan($sub,true);

    $sub->trial        = $orig_trial;
    $sub->trial_days   = $orig_trial_days;
    $sub->trial_amount = $orig_trial_amount;
    $sub->store();

    $args = MeprHooks::apply_filters('mepr_stripe_resume_subscription_args', array( 'plan' => $plan->id ), $sub);

    $this->email_status( "process_resume_subscription: \n" .
                         MeprUtils::object_to_string($sub, true) . "\n",
                         $this->settings->debug );

    $subscr = $this->send_stripe_request( "customers/{$sub->subscr_id}/subscription", $args, 'post' );

    $_REQUEST['data'] = $customer;
    return $this->record_resume_subscription();
  }

  /** This method should be used by the class to record a successful resuming of
    * as subscription from the gateway.
    */
  public function record_resume_subscription() {
    if(isset($_REQUEST['data'])) {
      $mepr_options = MeprOptions::fetch();

      $sdata = (object)$_REQUEST['data'];
      $sub = MeprSubscription::get_one_by_subscr_id($sdata->id);
      $sub->response=$sdata;
      $sub->status=MeprSubscription::$active_str;

      if( $card = $this->get_default_card($sdata) ) {
        $sub->cc_last4 = $card['last4'];
        $sub->cc_exp_month = $card['exp_month'];
        $sub->cc_exp_year = $card['exp_year'];
      }

      $sub->store();

      //Check if prior txn is expired yet or not, if so create a temporary txn so the user can access the content immediately
      $prior_txn = $sub->latest_txn();
      if(strtotime($prior_txn->expires_at) < time()) {
        $txn = new MeprTransaction();
        $txn->subscription_id = $sub->ID;
        $txn->trans_num  = $sub->subscr_id . '-' . uniqid();
        $txn->status     = MeprTransaction::$confirmed_str;
        $txn->txn_type   = MeprTransaction::$subscription_confirmation_str;
        $txn->response   = (string)$sub;
        $txn->expires_at = MeprUtils::ts_to_mysql_date(time()+MeprUtils::days(0), 'Y-m-d H:i:s');
        $txn->set_subtotal(0.00); // Just a confirmation txn
        $txn->store();
      }

      $this->send_resumed_sub_notices($sub);

      return array('subscription' => $sub, 'transaction' => $txn);
    }

    return false;
  }

  /** Used to cancel a subscription by the given gateway. This method should be used
    * by the class to record a successful cancellation from the gateway. This method
    * should also be used by any IPN requests or Silent Posts.
    */
  public function process_cancel_subscription($sub_id) {
    $mepr_options = MeprOptions::fetch();
    $sub = new MeprSubscription($sub_id);

    // If there's not already a customer then we're done here
    if(!($customer = $this->stripe_customer($sub_id))) { return false; }

    $args = MeprHooks::apply_filters('mepr_stripe_cancel_subscription_args', array(), $sub);

    $res = $this->send_stripe_request( "customers/{$customer->id}/subscription", $args, 'delete' );
    $_REQUEST['data'] = $res;

    return $this->record_cancel_subscription();
  }

  /** This method should be used by the class to record a successful cancellation
    * from the gateway. This method should also be used by any IPN requests or
    * Silent Posts.
    */
  public function record_cancel_subscription() {
    if(isset($_REQUEST['data']))
    {
      $sdata = (object)$_REQUEST['data'];
      if( $sub = MeprSubscription::get_one_by_subscr_id($sdata->customer) ) {
        // Seriously ... if sub was already cancelled what are we doing here?
        // Also, for stripe, since a suspension is only slightly different
        // than a cancellation, we kick it into high gear and check for that too
        if($sub->status == MeprSubscription::$cancelled_str or
           $sub->status == MeprSubscription::$suspended_str) { return $sub; }

        $sub->status = MeprSubscription::$cancelled_str;
        $sub->store();

        if(isset($_REQUEST['expire']))
          $sub->limit_reached_actions();

        if(!isset($_REQUEST['silent']) || ($_REQUEST['silent']==false))
          $this->send_cancelled_sub_notices($sub);
      }
    }

    return false;
  }

  /** This gets called on the 'init' hook when the signup form is processed ...
    * this is in place so that payment solutions like paypal can redirect
    * before any content is rendered.
  */
  public function process_signup_form($txn) {
    //if($txn->amount <= 0.00) {
    //  MeprTransaction::create_free_transaction($txn);
    //  return;
    //}
  }

  public function display_payment_page($txn) {
    // Nothing to do here ...
  }

  /** This gets called on wp_enqueue_script and enqueues a set of
    * scripts for use on the page containing the payment form
    */
  public function enqueue_payment_form_scripts() {
    wp_enqueue_script('stripe-js', 'https://js.stripe.com/v1/', array(), MEPR_VERSION);
    wp_enqueue_script('stripe-create-token', MEPR_GATEWAYS_URL . '/stripe/create_token.js', array('stripe-js', 'jquery.payment'), MEPR_VERSION);
    wp_localize_script('stripe-create-token', 'MeprStripeGateway', array( 'public_key' => $this->settings->public_key ));
  }

  /** This gets called on the_content and just renders the payment form
    */
  public function display_payment_form($amount, $user, $product_id, $txn_id) {
    $mepr_options = MeprOptions::fetch();
    $prd = new MeprProduct($product_id);
    $coupon = false;

    $txn = new MeprTransaction($txn_id);

    //Artifically set the price of the $prd in case a coupon was used
    if($prd->price != $amount)
    {
      $coupon = true;
      $prd->price = $amount;
    }

    $invoice = MeprTransactionsHelper::get_invoice($txn);
    echo $invoice;

    ?>
    <div class="mp_wrapper">
      <form action="<?php echo $prd->url('',true); ?>" method="post" id="payment-form" class="mepr-form" novalidate>
        <input type="hidden" name="mepr_process_payment_form" value="Y" />
        <input type="hidden" name="mepr_transaction_id" value="<?php echo $txn_id; ?>" />
        <input type="hidden" class="card-name" value="<?php echo $user->get_full_name(); ?>" />

        <?php if($mepr_options->show_address_fields && $mepr_options->require_address_fields): ?>
          <input type="hidden" class="card-address-1" value="<?php echo get_user_meta($user->ID, 'mepr-address-one', true); ?>" />
          <input type="hidden" class="card-address-2" value="<?php echo get_user_meta($user->ID, 'mepr-address-two', true); ?>" />
          <input type="hidden" class="card-city" value="<?php echo get_user_meta($user->ID, 'mepr-address-city', true); ?>" />
          <input type="hidden" class="card-state" value="<?php echo get_user_meta($user->ID, 'mepr-address-state', true); ?>" />
          <input type="hidden" class="card-zip" value="<?php echo get_user_meta($user->ID, 'mepr-address-zip', true); ?>" />
          <input type="hidden" class="card-country" value="<?php echo get_user_meta($user->ID, 'mepr-address-country', true); ?>" />
        <?php endif; ?>

        <div class="mepr-stripe-errors"></div>
        <?php MeprView::render('/shared/errors', get_defined_vars()); ?>

        <div class="mp-form-row">
          <div class="mp-form-label">
            <label><?php _e('Credit Card Number', 'memberpress'); ?></label>
            <span class="cc-error"><?php _e('Invalid Credit Card Number', 'memberpress'); ?></span>
          </div>
          <input type="text" class="mepr-form-input card-number cc-number validation" pattern="\d*" autocomplete="cc-number" required>
        </div>

        <input type="hidden" name="mepr-cc-type" class="cc-type" value="" />

        <div class="mp-form-row">
          <div class="mp-form-label">
            <label><?php _e('Expiration', 'memberpress'); ?></label>
            <span class="cc-error"><?php _e('Invalid Expiration', 'memberpress'); ?></span>
          </div>
          <input type="text" class="mepr-form-input cc-exp validation" pattern="\d*" autocomplete="cc-exp" placeholder="mm/yy" required>
          <?php //$this->months_dropdown('','card-expiry-month',isset($_REQUEST['card-expiry-month'])?$_REQUEST['card-expiry-month']:'',true); ?>
          <?php //$this->years_dropdown('','card-expiry-year',isset($_REQUEST['card-expiry-year'])?$_REQUEST['card-expiry-year']:''); ?>
        </div>

        <div class="mp-form-row">
          <div class="mp-form-label">
            <label><?php _e('CVC', 'memberpress'); ?></label>
            <span class="cc-error"><?php _e('Invalid CVC Code', 'memberpress'); ?></span>
          </div>
          <input type="text" class="mepr-form-input card-cvc cc-cvc validation" pattern="\d*" autocomplete="off" required>
        </div>

        <?php MeprHooks::do_action('mepr-stripe-payment-form', $txn); ?>

        <div class="mepr_spacer">&nbsp;</div>

        <input type="submit" class="mepr-submit" value="<?php _e('Submit', 'memberpress'); ?>" />
        <img src="<?php echo admin_url('images/loading.gif'); ?>" style="display: none;" class="mepr-loading-gif" />
        <?php MeprView::render('/shared/has_errors', get_defined_vars()); ?>

        <noscript><p class="mepr_nojs"><?php _e('Javascript is disabled in your browser. You will not be able to complete your purchase until you either enable JavaScript in your browser, or switch to a browser that supports it.', 'memberpress'); ?></p></noscript>
      </form>
    </div>
    <?php
  }

  /** Validates the payment form before a payment is processed */
  public function validate_payment_form($errors) {
    // This is done in the javascript with Stripe
  }

  /** Displays the form for the given payment gateway on the MemberPress Options page */
  public function display_options_form() {
    $mepr_options = MeprOptions::fetch();

    $test_secret_key = trim($this->settings->api_keys['test']['secret']);
    $test_public_key = trim($this->settings->api_keys['test']['public']);
    $live_secret_key = trim($this->settings->api_keys['live']['secret']);
    $live_public_key = trim($this->settings->api_keys['live']['public']);
    $force_ssl       = ($this->settings->force_ssl == 'on' or $this->settings->force_ssl == true);
    $debug           = ($this->settings->debug == 'on' or $this->settings->debug == true);
    $test_mode       = ($this->settings->test_mode == 'on' or $this->settings->test_mode == true);

    ?>
    <table id="mepr-stripe-test-keys-<?php echo $this->id; ?>" class="mepr-stripe-test-keys mepr-hidden">
      <tr>
        <td><?php _e('Test Secret Key*:', 'memberpress'); ?></td>
        <td><input type="text" class="mepr-auto-trim" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][api_keys][test][secret]" value="<?php echo $test_secret_key; ?>" /></td>
      </tr>
      <tr>
        <td><?php _e('Test Publishable Key*:', 'memberpress'); ?></td>
        <td><input type="text" class="mepr-auto-trim" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][api_keys][test][public]" value="<?php echo $test_public_key; ?>" /></td>
      </tr>
    </table>
    <table id="mepr-stripe-live-keys-<?php echo $this->id; ?>" class="mepr-stripe-live-keys mepr-hidden">
      <tr>
        <td><?php _e('Live Secret Key*:', 'memberpress'); ?></td>
        <td><input type="text" class="mepr-auto-trim" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][api_keys][live][secret]" value="<?php echo $live_secret_key; ?>" /></td>
      </tr>
      <tr>
        <td><?php _e('Live Publishable Key*:', 'memberpress'); ?></td>
        <td><input type="text" class="mepr-auto-trim" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][api_keys][live][public]" value="<?php echo $live_public_key; ?>" /></td>
      </tr>
    </table>
    <table>
      <tr>
        <td colspan="2"><input class="mepr-stripe-testmode" data-integration="<?php echo $this->id; ?>" type="checkbox" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][test_mode]"<?php echo checked($test_mode); ?> />&nbsp;<?php _e('Test Mode', 'memberpress'); ?></td>
      </tr>
      <tr>
        <td colspan="2"><input type="checkbox" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][force_ssl]"<?php echo checked($force_ssl); ?> />&nbsp;<?php _e('Force SSL', 'memberpress'); ?></td>
      </tr>
      <tr>
        <td colspan="2"><input type="checkbox" name="<?php echo $mepr_options->integrations_str; ?>[<?php echo $this->id;?>][debug]"<?php echo checked($debug); ?> />&nbsp;<?php _e('Send Debug Emails', 'memberpress'); ?></td>
      </tr>
      <tr>
        <td><?php _e('Stripe Webhook URL:', 'memberpress'); ?></td>
        <td><input type="text" onfocus="this.select();" onclick="this.select();" readonly="true" class="clippy_input" value="<?php echo $this->notify_url('whk'); ?>" /><span class="clippy"><?php echo $this->notify_url('whk'); ?></span></td>
      </tr>
    </table>
    <?php
  }

  /** Validates the form for the given payment gateway on the MemberPress Options page */
  public function validate_options_form($errors) {
    $mepr_options = MeprOptions::fetch();

    $testmode = isset($_REQUEST[$mepr_options->integrations_str][$this->id]['test_mode']);
    $testmodestr  = $testmode ? 'test' : 'live';

    if( !isset($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['secret']) or
         empty($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['secret']) or
        !isset($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['public']) or
         empty($_REQUEST[$mepr_options->integrations_str][$this->id]['api_keys'][$testmodestr]['public']) ) {
      $errors[] = __("All Stripe keys must be filled in.", 'memberpress');
    }

    return $errors;
  }

  /** This gets called on wp_enqueue_script and enqueues a set of
    * scripts for use on the front end user account page.
    */
  public function enqueue_user_account_scripts() {
    wp_enqueue_script('stripe-js', 'https://js.stripe.com/v1/', array(), MEPR_VERSION);
    wp_enqueue_script('stripe-create-token', MEPR_GATEWAYS_URL . '/stripe/create_token.js', array('stripe-js'), MEPR_VERSION);
    wp_localize_script('stripe-create-token', 'MeprStripeGateway', array( 'public_key' => $this->settings->public_key ));
  }

  /** Displays the update account form on the subscription account page **/
  public function display_update_account_form($sub_id, $errors=array(), $message='') {
    $mepr_options = MeprOptions::fetch();
    $customer = $this->stripe_customer($sub_id);
    $sub = new MeprSubscription($sub_id);
    $usr = $sub->user();

    $cc_exp_month = isset($_REQUEST['card-expiry-month'])?$_REQUEST['card-expiry-month']:$sub->cc_exp_month;
    $cc_exp_year = isset($_REQUEST['card-expiry-year'])?$_REQUEST['card-expiry-year']:$sub->cc_exp_year;

    if( $card = $this->get_default_card($customer) ) {
      $card_num = MeprUtils::cc_num($card['last4']);
      $card_name = ( isset($card['name']) and $card['name']!='undefined' ) ? $card['name'] : $usr->get_full_name();
    }
    else {
      $card_num = $sub->cc_num();
      $card_name = $usr->get_full_name();
    }

    ?>
    <div class="mp_wrapper">
      <form action="" method="post" id="payment-form" class="mepr-form" novalidate>
        <input type="hidden" name="_mepr_nonce" value="<?php echo wp_create_nonce('mepr_process_update_account_form'); ?>" />
        <input type="hidden" class="card-name" value="<?php echo $card_name; ?>" />

        <?php if($mepr_options->show_address_fields && $mepr_options->require_address_fields): ?>
          <input type="hidden" class="card-address-1" value="<?php echo get_user_meta($usr->ID, 'mepr-address-one', true); ?>" />
          <input type="hidden" class="card-address-2" value="<?php echo get_user_meta($usr->ID, 'mepr-address-two', true); ?>" />
          <input type="hidden" class="card-city" value="<?php echo get_user_meta($usr->ID, 'mepr-address-city', true); ?>" />
          <input type="hidden" class="card-state" value="<?php echo get_user_meta($usr->ID, 'mepr-address-state', true); ?>" />
          <input type="hidden" class="card-zip" value="<?php echo get_user_meta($usr->ID, 'mepr-address-zip', true); ?>" />
          <input type="hidden" class="card-country" value="<?php echo get_user_meta($usr->ID, 'mepr-address-country', true); ?>" />
        <?php endif; ?>

        <div class="mepr_update_account_table">
          <div><strong><?php _e('Update your Credit Card information below', 'memberpress'); ?></strong></div><br/>

          <div class="mepr-stripe-errors"></div>
          <?php MeprView::render('/shared/errors', get_defined_vars()); ?>

          <div class="mp-form-row">
            <div class="mp-form-label">
              <label><?php _e('Credit Card Number', 'memberpress'); ?></label>
              <span class="cc-error"><?php _e('Invalid Credit Card Number', 'memberpress'); ?></span>
            </div>
            <input type="text" class="mepr-form-input card-number cc-number validation" pattern="\d*" autocomplete="cc-number" placeholder="<?php echo $card_num; ?>" required>
          </div>

          <input type="hidden" name="mepr-cc-type" class="cc-type" value="" />

          <div class="mp-form-row">
            <div class="mp-form-label">
              <label><?php _e('Expiration', 'memberpress'); ?></label>
              <span class="cc-error"><?php _e('Invalid Expiration', 'memberpress'); ?></span>
            </div>
            <input type="text" class="mepr-form-input cc-exp validation" pattern="\d*" autocomplete="cc-exp" placeholder="mm/yy" required>
          </div>

          <div class="mp-form-row">
            <div class="mp-form-label">
              <label><?php _e('CVC', 'memberpress'); ?></label>
              <span class="cc-error"><?php _e('Invalid CVC Code', 'memberpress'); ?></span>
            </div>
            <input type="text" class="mepr-form-input card-cvc cc-cvc validation" pattern="\d*" autocomplete="off" required>
          </div>

          <div class="mepr_spacer">&nbsp;</div>

          <input type="submit" class="mepr-submit" value="<?php _e('Update Credit Card', 'memberpress'); ?>" />
          <img src="<?php echo admin_url('images/loading.gif'); ?>" style="display: none;" class="mepr-loading-gif" />
          <?php MeprView::render('/shared/has_errors', get_defined_vars()); ?>
        </div>
      </form>
    </div>
    <?php
  }

  /** Validates the payment form before a payment is processed */
  public function validate_update_account_form($errors=array()) {
    return $errors;
  }

  /** Used to update the credit card information on a subscription by the given gateway.
    * This method should be used by the class to record a successful cancellation from
    * the gateway. This method should also be used by any IPN requests or Silent Posts.
    */
  public function process_update_account_form($sub_id) {
    $this->process_update_subscription($sub_id);
  }

  /** Returns boolean ... whether or not we should be sending in test mode or not */
  public function is_test_mode() {
    return (isset($this->settings->test_mode) and $this->settings->test_mode);
  }

  public function force_ssl() {
    return (isset($this->settings->force_ssl) and ($this->settings->force_ssl == 'on' or $this->settings->force_ssl == true));
  }

  /** STRIPE SPECIFIC METHODS **/

  public function listener() {
    // retrieve the request's body and parse it as JSON
    $body = @file_get_contents('php://input');
    $event_json = (object)json_decode($body,true);

    if(!isset($event_json->id)) return;

    // Use the id to pull the event directly from the API (purely a security measure)
    try {
      $event = (object)$this->send_stripe_request( "events/{$event_json->id}", array(), 'get' );
    }
    catch( Exception $e ) {
      return; // Do nothing
    }
    //$event = $event_json;

    $_REQUEST['data'] = $obj = (object)$event->data['object'];

    if($event->type=='charge.succeeded') {
      $this->email_status("###Event: {$event->type}\n" . MeprUtils::object_to_string($event, true)."\n", $this->settings->debug);

      // Description only gets set with the txn id in a standard charge
      if(isset($obj->description)) {
        //$this->record_payment(); // done on page
      }
      elseif(isset($obj->customer))
        $this->record_subscription_payment();
    }
    else if($event->type=='charge.failed') {
      $this->record_payment_failure();
    }
    else if($event->type=='charge.refunded') {
      $this->record_refund();
    }
    else if($event->type=='charge.disputed') {
      // Not worried about this right now
    }
    else if($event->type=='customer.subscription.created') {
      //$this->record_create_subscription(); // done on page
    }
    else if($event->type=='customer.subscription.updated') {
      //$this->record_update_subscription(); // done on page
    }
    else if($event->type=='customer.subscription.deleted') {
      $this->record_cancel_subscription();
    }
    else if($event->type=='customer.subscription.trial_will_end') {
      // We may want to implement this feature at some point
    }
  }

  // Originally I thought these should be associated with
  // our membership objects but now I realize they should be
  // associated with our subscription objects
  public function stripe_plan($sub, $is_new = false) {
    $mepr_options = MeprOptions::fetch();
    $prd = $sub->product();

    try {
      if($is_new)
        $plan_id = $this->create_new_plan_id($sub);
      else
        $plan_id = $this->get_plan_id($sub);

      $stripe_plan = $this->send_stripe_request( "plans/{$plan_id}", array(), 'get' );
    }
    catch( Exception $e ) {
      // The call resulted in an error ... meaning that
      // there's no plan like that so let's create one
      if( $sub->period_type == 'months' )
        $interval = 'month';
      else if( $sub->period_type == 'years' )
        $interval = 'year';
      else if( $sub->period_type == 'weeks' )
        $interval = 'week';

      //Setup a new plan ID and store the meta with this subscription
      $new_plan_id = $this->create_new_plan_id($sub);

      //Handle zero decimal currencies in Stripe
      $amount = (MeprUtils::is_zero_decimal_currency())?MeprUtils::format_float(($sub->price), 0):MeprUtils::format_float(($sub->price * 100), 0);

      $args = MeprHooks::apply_filters('mepr_stripe_create_plan_args', array(
        'amount' => $amount,
        'interval' => $interval,
        'interval_count' => $sub->period,
        'name' => $prd->post_title,
        'currency' => $mepr_options->currency_code,
        'id' => $new_plan_id,
        'statement_descriptor' => substr(get_option('blogname'), 0, 21)
      ), $sub);

      if($sub->trial) {
        $args = array_merge(array("trial_period_days"=>$sub->trial_days), $args);
      }

      // Don't enclose this in try/catch ... we want any errors to bubble up
      $stripe_plan = $this->send_stripe_request( 'plans', $args );
    }

    return (object)$stripe_plan;
  }

  public function get_plan_id($sub) {
    $meta_plan_id = get_post_meta($sub->ID, self::$stripe_plan_id_str, true);

    if($meta_plan_id == '')
      return $sub->ID;
    else
      return $meta_plan_id;
  }

  public function create_new_plan_id($sub) {
    $parse = parse_url(home_url());
    $new_plan_id = $sub->ID . '-' . $parse['host'] . '-' . uniqid();
    update_post_meta($sub->ID, self::$stripe_plan_id_str, $new_plan_id);
    return $new_plan_id;
  }

  public function stripe_customer( $sub_id, $cc_token=null ) {
    $mepr_options = MeprOptions::fetch();
    $sub = new MeprSubscription($sub_id);
    $user = $sub->user();

    $stripe_customer = (object)$sub->response;

    $uid = uniqid();
    $this->email_status("###{$uid} Stripe Customer (should be blank at this point): \n" . MeprUtils::object_to_string($stripe_customer, true) . "\n", $this->settings->debug);

    if( !$stripe_customer or empty($stripe_customer->id) ) {
      if( empty($cc_token) )
        return false;
      else {
        $stripe_args = MeprHooks::apply_filters('mepr_stripe_customer_args', array(
          'card' => $cc_token,
          'email' => $user->user_email,
          'description' => $user->get_full_name()
        ), $sub);
        $stripe_customer = (object)$this->send_stripe_request( 'customers', $stripe_args );
        $sub->subscr_id = $stripe_customer->id;
        $sub->response  = $stripe_customer;
        $sub->store();
      }
    }

    $this->email_status("###{$uid} Stripe Customer (should not be blank at this point): \n" . MeprUtils::object_to_string($stripe_customer, true) . "\n", $this->settings->debug);

    return (object)$stripe_customer;
  }

  public function send_stripe_request( $endpoint,
                                       $args=array(),
                                       $method='post',
                                       $domain='https://api.stripe.com/v1/',
                                       $blocking=true ) {
    $uri = "{$domain}{$endpoint}";

    $args = MeprHooks::apply_filters('mepr_stripe_request_args', $args);

    $arg_array = MeprHooks::apply_filters('mepr_stripe_request', array(
      'method'    => strtoupper($method),
      'body'      => $args,
      'timeout'   => 15,
      'blocking'  => $blocking,
      'sslverify' => false, // We assume the cert on stripe is trusted
      'headers'   => array(
        'Authorization' => 'Basic ' . base64_encode("{$this->settings->secret_key}:")
      )
    ));

    $uid = uniqid();
    //$this->email_status("###{$uid} Stripe Call to {$uri} API Key: {$this->settings->secret_key}\n" . MeprUtils::object_to_string($arg_array, true) . "\n", $this->settings->debug);

    $resp = wp_remote_request( $uri, $arg_array );

    // If we're not blocking then the response is irrelevant
    // So we'll just return true.
    if( $blocking==false )
      return true;

    if( is_wp_error( $resp ) ) {
      throw new MeprHttpException( sprintf( __( 'You had an HTTP error connecting to %s' , 'memberpress'), $this->name ) );
    }
    else {
      if( null !== ( $json_res = json_decode( $resp['body'], true ) ) ) {
        //$this->email_status("###{$uid} Stripe Response from {$uri}\n" . MeprUtils::object_to_string($json_res, true) . "\n", $this->settings->debug);
        if( isset($json_res['error']) )
          throw new MeprRemoteException( "{$json_res['error']['message']} ({$json_res['error']['type']})" );
        else
          return $json_res;
      }
      else // Un-decipherable message
        throw new MeprRemoteException( sprintf( __( 'There was an issue with the credit card processor. Try again later.', 'memberpress'), $this->name ) );
    }

    return false;
  }

  /** Get the default card object from a subscription creation response */
  public function get_default_card($data) {
    $data = (object)$data; // ensure we're dealing with a stdClass object

    if(isset($data->default_source)) { // Added in version 2015-02-15 of stripe's API
      foreach($data->sources['data'] as $source) {
        if($source['id']==$data->default_source) { return $source; }
      }
    }
    else if(isset($data->default_card)) { // Added in version 2013-07-05 of stripe's API
      foreach($data->cards['data'] as $card) {
        if($card['id']==$data->default_card) { return $card; }
      }
    }
    else if(isset($data->active_card)) { // Removed in version 2013-07-05 of stripe's API
      return $data->active_card;
    }

    return false;
  }

  /** Get card object from a charge response */
  public function get_card($data) {
    // the card object is no longer returned as of 2015-02-18 ... instead it returns 'source'
    if(isset($data->source) && $data->source['object']=='card') {
      return $data->source;
    }
    elseif(isset($data->card)) {
      return $data->card;
    }
  }
}
