<?php
/**
 * Plugin Name: Id Egov Plugin
 * Description: OAuth2 Authorization Plugin
 * Plugin URI:  https://id.egov.uz
 * Author URI:  /#
 * Author:      Andrew Magzumov
 * Version:     1.1
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Network:     true
 **/

define('DISALLOW_FILE_MODS',false );
require_once($_SERVER['DOCUMENT_ROOT'] . '//wp-config.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '//wp-includes/wp-db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '//wp-admin/includes/taxonomy.php');

/*if(isset($_GET['username'])){
    wp_create_user( $_GET['username'], '', $_GET['email'] );
}*/

if ( ! function_exists( 'post_exists' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/post.php' );
}

### дополнительные данные на странице профиля
add_action('show_user_profile', 'my_profile_new_fields_add');
add_action('edit_user_profile', 'my_profile_new_fields_add');

add_action('personal_options_update', 'my_profile_new_fields_update');
add_action('edit_user_profile_update', 'my_profile_new_fields_update');

function my_profile_new_fields_add(){
    $user_ID = get_current_user_id();
    $account = get_user_meta( $user_ID, "phone", 1 );
    ?>
    <h3>Дополнительные данные</h3>
    <table class="form-table">
        <tr>
            <th><label for="user_fb_txt">Номер телефона</label></th>
            <td>
                <input type="text" name="phone" value="<?php echo $account ?>"><br>
            </td>
        </tr>
    </table>
    <?php
}

// обновление
function my_profile_new_fields_update(){
    $user_ID = get_current_user_id();

    update_user_meta( $user_ID, "phone", $_POST['phone'] );
}


//Добавляем шорткод для кнопки
add_action('id_egov_button_shortcode','my_shortcode_output');

//выводим на экран login кнопку
function my_shortcode_output(){
    $html = '';
    $html .= '<a href="'.plugin_dir_url( __FILE__ ).'idegovform.php'.'" class="button button-primary button-large" style="background-color: #4825C2; width: 100%;height: 100%;border-radius: 15px;margin-bottom: 15px">
                <img style="margin-right: 55px; margin-left: 55px; padding-top: 7px;" src="https://id.egov.uz/assets/svg/logo.svg" />
              </a>
              <div class="form-check">
                  <a href="javascript:void(0)" class="admin_enter hide">Вход в админку</a>
                </div>
                <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
                <script type="text/javascript">
                $(document).ready(function() {
                  $("body .forgetmenot").hide();
                  $("body #loginform p").hide();
                  $("body .user-pass-wrap").hide();
                  $("body .admin_enter").click(function() {
                    if($(this).hasClass("hide")){
                        $("body .forgetmenot").hide();
                          $("body #loginform p").hide();
                          $("body .user-pass-wrap").hide();
                          $("body #user_pass").removeAttr("disabled");
                          $(this).removeClass("hide");
                          $(this).addClass("show");
                    }else if($(this).hasClass("show")){
                        $("body .forgetmenot").show();
                          $("body #loginform p").show();
                        $("body .user-pass-wrap").show();
                        $("body #user_pass").removeAttr("disabled");
                        
                        $(this).removeClass("show");
                        $(this).addClass("hide");
                    }
                  });
                  
                })  
                
                </script>';
    echo $html;
}

function my_plugin_activate() {
    add_option( 'Activated_Plugin', 'Plugin-Slug' );
    addIDEGOVBtn();
}
register_activation_hook( __FILE__, 'my_plugin_activate' );

function addIDEGOVBtn(){
    $search      = "do_action( 'login_form' );";
    $lines       = file($_SERVER['DOCUMENT_ROOT']."/wp-login.php");
    $line_number = false;

    while (list($key, $line) = each($lines) and !$line_number) {
        $line_number = (strpos($line, $search) !== FALSE) ? $key + 1 : $line_number;
    }

    $file = $_SERVER['DOCUMENT_ROOT']."/wp-login.php";
    $content = file($file); //Read the file into an array. Line number => line content
    foreach($content as $lineNumber => &$lineContent) { //Loop through the array (the "lines")
        if($lineNumber == $line_number-1) { //Remember we start at line 0.
            $lineContent .= "do_action('id_egov_button_shortcode');" . PHP_EOL; //Modify the line. (We're adding another line by using PHP_EOL)
        }
    }

    $allContent = implode("", $content); //Put the array back into one string
    file_put_contents($file, $allContent); //Overwrite the file with the new content
}


function my_plugin_deactivate() {
    add_option( 'Deactivate_Plugin', 'Plugin-Slug' );
    removeIDEGOVBtn();
}

register_deactivation_hook(__FILE__, 'my_plugin_deactivate');


function removeIDEGOVBtn(){
    $contents = file_get_contents($_SERVER['DOCUMENT_ROOT']."/wp-login.php");
    $contents = str_replace("do_action('id_egov_button_shortcode');", '', $contents);
    file_put_contents($_SERVER['DOCUMENT_ROOT']."/wp-login.php", $contents);

    /*$search      = "do_action('id_egov_button_shortcode');";
    $lines       = file($_SERVER['DOCUMENT_ROOT']."/wp-login.php");
    $line_number = false;

    while (list($key, $line) = each($lines) and !$line_number) {
        $line_number = (strpos($line, $search) !== FALSE) ? $key + 1 : $line_number;
    }

    $file = $_SERVER['DOCUMENT_ROOT']."/wp-login.php";
    $content = file($file); //Read the file into an array. Line number => line content
    foreach($content as $lineNumber => &$lineContent) { //Loop through the array (the "lines")
        if($lineNumber == $line_number-2) { //Remember we start at line 0.
            $lineContent .= "" .$line_number . PHP_EOL; //Modify the line. (We're adding another line by using PHP_EOL)
        }
    }

    $allContent = implode("", $content); //Put the array back into one string
    file_put_contents($file, $allContent);*/ //Overwrite the file with the new content
}

/*$contents = file_get_contents($dir);
$contents = str_replace($line, '', $contents);
file_put_contents($dir, $contents);*/