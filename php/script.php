<?php

$content = json_decode(filter_input(INPUT_POST, 'messageData'));

if (empty($content->{'messages'}[0]->{'sender'})) {
    exit('warning_sender_is_not_set');
} elseif (empty($content->{'messages'}[0]->{'text'})) {
    exit('warning_text_is_not_set');
} elseif (count($content->{'messages'}[0]->{'recipients'}) == 0) {
    exit('warning_recipients_is_not_set');
}
    
$config = file_get_contents('../config/infobip_account.json');
$content->{'authentication'} = json_decode($config);

if (mb_strlen($content->{'messages'}[0]->{'text'}, 'UTF-8') > 160) {
    $content->{'messages'}[0]->{'type'} = 'longSMS';
}

$options = array(
    'http' => array(
        'method' => 'POST',
        'content' => json_encode($content),
        'header' => 'Content-Type: application/json\r\n' .
        'Accept: */*\r\n'
    )
);

$url = 'http://api.infobip.com/api/v3/sendsms/json';
$context = stream_context_create($options);
$result = file_get_contents($url, false, $context);
$response = json_decode($result);

//Logging things here
$db = new PDO('sqlite:../db/smslog.sqlite');

if ($db && ($response->{'results'}[0]->{'status'} != '-22')) {
    $db->exec('CREATE TABLE IF NOT EXISTS response_log ('
        . 'Id INTEGER PRIMARY KEY,'
        . 'sender_id INTEGER,'
        . 'phonenumber_id INTEGER,'
        . 'messagetext_id INTEGER,'
        . 'status INTEGER,'
        . 'FOREIGN KEY(sender_id) REFERENCES sender(sender_id),'
        . 'FOREIGN KEY(phonenumber_id) REFERENCES phone_number(phonenumber_id),'
        . 'FOREIGN KEY(messagetext_id) REFERENCES message_text(messagetext_id)'
        . ')');

    $db->exec('CREATE TABLE IF NOT EXISTS message_text ('
        . 'messagetext_id INTEGER PRIMARY KEY,'
        . 'messagetext TEXT)');

    $db->exec('CREATE TABLE IF NOT EXISTS phone_number ('
        . 'phonenumber_id INTEGER PRIMARY KEY,'
        . 'phonenumber TEXT)');

    $db->exec('CREATE TABLE IF NOT EXISTS sender ('
        . 'sender_id INTEGER PRIMARY KEY,'
        . 'sender TEXT)');

    $stmt = $db->prepare('INSERT INTO sender(sender) SELECT :sender WHERE NOT EXISTS(SELECT sender FROM sender WHERE sender = :sender)');
    $stmt->bindParam(':sender', $content->{'messages'}[0]->{'sender'});
    $stmt->execute();

    $stmt = $db->prepare('INSERT INTO message_text(messagetext) SELECT :messagetext WHERE NOT EXISTS(SELECT messagetext FROM message_text WHERE messagetext = :messagetext)');
    $stmt->bindParam(':messagetext', $content->{'messages'}[0]->{'text'});
    $stmt->execute();

    foreach ($content->{'messages'}[0]->{'recipients'} as $recipient) {
        $stmt = $db->prepare('INSERT INTO phone_number(phonenumber) SELECT :phonenumber WHERE NOT EXISTS(SELECT phonenumber FROM phone_number WHERE phonenumber = :phonenumber)');
        $stmt->bindParam(':phonenumber', $recipient->{'gsm'});
        $stmt->execute();
    }

    foreach ($response->{'results'} as $item) {
        $stmt = $db->prepare('
            INSERT INTO response_log(sender_id, phonenumber_id, messagetext_id, status)
            SELECT t1.sender_id, t2.phonenumber_id, t3.messagetext_id, :status FROM (
                 (SELECT sender_id FROM sender WHERE sender = :sender) as t1
                 LEFT JOIN
                 (SELECT phonenumber_id FROM phone_number WHERE phonenumber = :phonenumber) as t2
                 LEFT JOIN
                 (SELECT messagetext_id FROM message_text WHERE messagetext = :messagetext) as t3
            )
        ');
        $stmt->bindParam(':sender', $content->{'messages'}[0]->{'sender'});
        $stmt->bindParam(':phonenumber', $item->{'destination'});
        $stmt->bindParam(':messagetext', $content->{'messages'}[0]->{'text'});
        $stmt->bindParam(':status', $item->{'status'});
        $stmt->execute();
    }
    echo 'success';
}