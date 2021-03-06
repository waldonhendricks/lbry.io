<?php

class Mailgun
{
  const BASE_URL = 'https://api.mailgun.net/v3';

  const TOP_DOMAIN = 'lbry.io';
  const MAIL_DOMAIN = 'mail.lbry.io';

  const LIST_GENERAL = 'lbryians@lbry.io';

  public static function unsubscribeFromMailingList($listAddress, $email)
  {
    list($status, $headers, $body) = static::put('/lists/' . $listAddress . '/members/' . $email, [
      'subscribed' => 'no',
    ]);

    if ($status == 200)
    {
      return true;
    }

    $data = json_decode($body, true);
    $message = $data['error'] ?? $data['message'] ?? false;

    if (strpos($message, 'Member '.$email) === 0 && strrpos($message, ' not found') === (strlen($message) - strlen(' not found')))
    {
      // message says "Member $email ... not found", so email is not on list. that's the same as unsubscribing, right?
      return true;
    }

    return $message;
  }

  public static function sendDmcaReport($data)
  {
    list($status, $headers, $body) = static::post('/' . static::MAIL_DOMAIN . '/messages', [
      'from'              => 'LBRY <mail@' . static::MAIL_DOMAIN . '>',
      'to'                => 'jeremy@lbry.io',
      'subject'           => 'DMCA Report #' . $data['report_id'],
      'html'              => '<pre>' . var_export($data, true) . '</pre>',
      'o:tracking-clicks' => 'no',
      'o:tracking-opens'  => 'no'
    ]);

    return $status == 200;
  }

  public static function sendYouTubeWarmLead($data)
  {
    list($status, $headers, $body) = static::post('/' . static::MAIL_DOMAIN . '/messages', [
      'from'              => 'LBRY <mail@' . static::MAIL_DOMAIN . '>',
      'to'                => 'reilly@lbry.io',
      'subject'           => 'Interested YouTuber',
      'html'              => '<pre>' . var_export($data, true) . '</pre>',
      'o:tracking-clicks' => 'no',
      'o:tracking-opens'  => 'no'
    ]);

    return $status == 200;
  }

  public static function sendSubscriptionConfirmation($email)
  {
    $confirmHash = static::getConfirmHash($email);
    list($status, $headers, $body) = static::post('/' . static::MAIL_DOMAIN . '/messages', [
      'from'              => 'LBRY <mail@' . static::MAIL_DOMAIN . '>',
      'to'                => $email,
      'h:Reply-To'        => 'help@lbry.io',
      'subject'           => __('email.confirm_email_subject'),
      'html'              => static::inlineCss(View::render('email_templates/_confirmHash', [
        'confirmHash' => $confirmHash
      ])),
      'o:tracking-clicks' => 'no',
      'o:tracking-opens'  => 'no'
    ]);

    return $status == 200;
  }

  public static function addToMailingList($listAddress, $email)
  {
    list($status, $headers, $body) = static::post('/lists/' . $listAddress . '/members', [
      'address' => $email,
//      'subscribed' => 'yes',
//      'upsert' => 'no', //https://documentation.mailgun.com/api-mailinglists.html#mailing-lists
    ]);

    if ($status != 200)
    {
      return json_decode($body, true)['message'];
    }

    return true;
  }

  protected static function getConfirmHash($email, $timestamp = null, $nonce = null, $useOldSecret = false)
  {
    $timestamp = $timestamp !== null ? $timestamp : time();
    $nonce     = $nonce !== null ? $nonce : bin2hex(random_bytes(8));
    $secret    = $useOldSecret ? 'testing' : Config::get('mailing_list_hmac_secret');

    if (!$secret)
    {
      throw new RuntimeException('Mailing list HMAC secret is missing');
    }

    return Encoding::base64EncodeUrlsafe(join('|', [
      $email, $timestamp, $nonce, hash_hmac('sha256', $email . $timestamp . $nonce, $secret)
    ]));
  }

  public static function checkConfirmHashAndGetEmail($hash)
  {
    $parts = explode('|', Encoding::base64DecodeUrlsafe($hash));
    if (count($parts) !== 4)
    {
      return null;
    }

    list($email, $timestamp, $nonce, $signature) = $parts;

    if (!hash_equals(static::getConfirmHash($email, $timestamp, $nonce), $hash))
    {
      // TODO: once old hashes expire, just return null here
      if (date('Y-m-d') >= '2016-11-18' || !hash_equals(static::getConfirmHash($email, $timestamp, $nonce, true), $hash))
      {
        return null;
      }
    }

    if (!is_numeric($timestamp) || time() - $timestamp > 60 * 60 * 24 * 3)
    {
      return null;
    }

    return $email;
  }

  protected static function post($endpoint, $data)
  {
    return static::request(Curl::POST, $endpoint, $data);
  }

  protected static function put($endpoint, $data)
  {
    return static::request(Curl::PUT, $endpoint, $data);
  }

  protected static function request($method, $endpoint, $data)
  {
    return Curl::doCurl($method, self::BASE_URL . $endpoint, $data, [
      'headers' => [
        'Authorization: Basic ' . base64_encode('api:' . Config::get('mailgun_api_key'))
      ],
      'retry' => 3,
    ]);
  }

  protected static function inlineCss($html, $css = '')
  {
    $e = new \Pelago\Emogrifier($html, $css);
    return trim($e->emogrify());
  }
}
