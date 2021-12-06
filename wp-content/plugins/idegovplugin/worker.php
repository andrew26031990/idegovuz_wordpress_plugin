<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '//wp-config.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '//wp-includes/wp-db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '//wp-admin/includes/taxonomy.php');

$authCode = $_GET["code"];

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL,'http://sso.egov.uz:8443/sso/oauth/Authorization.do');
curl_setopt($ch, CURLOPT_POST, true);

$param = "grant_type=" . rawurlencode('one_authorization_code');
$param = $param . "&client_id=" . rawurlencode('emis_mdo_uz');
$param = $param . "&client_secret=" . rawurlencode('Ighgg6juJ4imGEbSRdiDHA==');
$param = $param . "&code=" . rawurlencode($authCode);
$param = $param . "&scope=" . rawurlencode('emis_mdo_uz');
$param = $param . "&redirect_uri=" . rawurlencode("");

curl_setopt($ch, CURLOPT_POSTFIELDS, $param);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$result = curl_exec ($ch);
curl_close ($ch);

$obj = json_decode($result);

$access_token = $obj->{'access_token'};

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL,'http://sso.egov.uz:8443/sso/oauth/Authorization.do');
curl_setopt($ch, CURLOPT_POST, true);

$param = "grant_type=" . rawurlencode('one_access_token_identify');
$param = $param . "&client_id=" . rawurlencode('emis_mdo_uz');
$param = $param . "&client_secret=" . rawurlencode('Ighgg6juJ4imGEbSRdiDHA==');
$param = $param . "&scope=" . rawurlencode('emis_mdo_uz');
$param = $param . "&access_token=" . rawurlencode($access_token);

curl_setopt($ch, CURLOPT_POSTFIELDS, $param);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$results = curl_exec ($ch);
curl_close ($ch);

$result = json_decode($results);


$user = $result->full_name;
$pin = $result->pin;
$tin = $result->tin;
$email = $result->email;
$pinfl = $result->pin;
$phone = $result->mob_phone_no;
$login = $result->user_id;
$first_name = $result->first_name;
$surname_name = $result->sur_name;

$pass = '123456789';

$id = wp_create_user( $login, $pass, $email );
wp_update_user( array( 'ID' => $id, 'display_name' => $user, 'username'=>$user ) );
update_user_meta( $id, 'phone', $phone);
update_user_meta( $id, 'first_name', $first_name);
update_user_meta( $id, 'last_name', $surname_name);
update_user_meta( $id, 'pinfl', $pinfl);
update_user_meta( $id, 'wp_capabilities', 'a:0:{}');
//abc_assign_role($id->id);

$auth = wp_authenticate( $login, $pass );

// Проверка ошибок
if ( is_wp_error( $auth ) ) {
    $error_string = $auth->get_error_message();
    echo '<div id="message" class="error"><p>' . $error_string . '</p></div>';
}
else {
    wp_set_auth_cookie( $auth->ID );
    wp_redirect( home_url() );
}

function abc_assign_role( $user_id ) {
    // Instantiate user object.
    $userobj = get_userdata( $user_id );
    // Define new role using the login name of this user object.
    $new_role = $userobj->user_login;

    // Create the role.
    add_role(
        $new_role,
        __( $new_role ),
        array(
            'read'         => true,
            'edit_posts'   => true,
            'delete_posts' => false,
        )
    );

    wp_update_user( array( 'ID' => $user_id, 'role' => $new_role ) );
}

