<?php
$ini_array = parse_ini_file(dirname(__FILE__)."//settings.ini");
if( isset($_SERVER['HTTPS'] ) ) {
    $http = "https://";
}else{
    $http = "http://";
}
?>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Cache-Control" content="no-cache">
</head>
<body>
<form name="OAuthForm" action="<?php echo $ini_array['auth_url']?>" method="get" />
<input type="hidden" name="response_type" value="<?php echo $ini_array['response_type']?>" />
<input type="hidden" name="client_id" value="<?php echo $ini_array['client_id']?>" />
<input type="hidden" name="redirect_uri" value="<?php echo $http.$_SERVER['SERVER_NAME']?>/wp-content/plugins/idegovplugin/worker.php" />
<input type="hidden" name="scope" value="<?php echo $ini_array['scope']?>" />
-<input type="hidden" name="state" value="<?php echo $ini_array['state']?>" />
</form>
<script type="text/javascript">
    document.OAuthForm.submit();
</script>
</body>
</html>
<?php die(); ?>

