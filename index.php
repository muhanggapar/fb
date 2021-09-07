<?php
// Facebook Multi Page/Group Poster v4
// Created by Novartis (Safwan)
ob_start();
error_reporting(0);
if ((isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on'))
{
    $_SERVER['HTTPS'] = 'on';
}
if (file_exists('config.php')) require_once ('config.php');
else  require_once ('functions.php');
if ($hardDemo && isset($_GET['framed'])) die('<br><br><h2><center><a href="./" target="_top">Click here to continue to Online Demo</a></center></h2>');
require_once ('includes/RestrictCSRF.php');
//DB existence check, Creates DB files if not present
if (!file_exists('params.php')) require ('includes/createdbs.php');
else  require_once ('params.php');
if (!file_exists($dbName . '-settings.db') || !file_exists($dbName . '-logs.db') || !file_exists($dbName . '-crons.db') || !file_exists($dbName . '-users.db') || !file_exists($dbName . '-presets.db')) require ('includes/createdbs.php');
readSettings();
require_once ('lang/en-lang.php');
if ((isset($_GET['lang']) || isset($_COOKIE['FBMPGPLang'])) && file_exists('lang/' . (isset($_GET['lang']) ? $_GET['lang'] : $_COOKIE['FBMPGPLang']) . '-lang.php')) require_once ('lang/' . (isset($_GET['lang']) ? $_GET['lang'] : $_COOKIE['FBMPGPLang']) . '-lang.php');
else  require_once ('lang/' . $adminOptions['lang'] . '-lang.php');
$plugins = glob("plugins/" . "*.php");
foreach ($plugins as $plugin)
{
    $pluginName = substr($plugin, 8, -4);
    if ($adminOptions['plug_' . $pluginName]) require_once ($plugin);
}
if ($adminOptions['scriptTitle'] != "") $lang['Script Title'] = $adminOptions['scriptTitle'];
if ($adminOptions['scriptHeading'] != "") $lang['Heading'] = $adminOptions['scriptHeading'];
if (isset($_GET['lang']) && file_exists('lang/' . $_GET['lang'] . '-lang.php'))
{
    setcookie("FBMPGPLang", $_GET['lang'], time() + 86400 * 365);
    $_COOKIE['FBMPGPLang'] = $_GET['lang'];
}
if (isset($_COOKIE['FBMPGPLang']) && !file_exists('lang/' . $_COOKIE['FBMPGPLang'] . '-lang.php'))
{
    setcookie("FBMPGPLang", '', time() - 50000);
    unset($_COOKIE['FBMPGPLang']);
}
//Is this a logout request?
if (isset($_GET['logout']))
{
    setcookie("FBMPGPLogin", '', time() - 50000);
    setcookie("FBMPGPUserID", '', time() - 50000);
    header("Location: ./");
    exit;
}
//Is this a logged in user show help/documentation request?
if (isset($_GET['showhelp']))
{
    showHelp();
}
//At this point we check all Input for XSS/SQLInjection attack, terminate execution if found!
xssSqlClean();
//Is this an Image Proxy Request?
if (isset($_GET['proxyurl']))
{
    require_once ('includes/proxy.php');
}
// initialize Facebook class using your own Facebook App credentials
require_once ("src/facebook.php");
$fb = new Facebook($config);
// Now we must check if the user is authorized. User might be logging in, authorizing the script or it may be a FB redirect request during the authorization process.
// So, first we check if we are on FB redirect during the authorization process.
if (isset($_GET['code']))
{
    require_once ('includes/fbauth.php');
}
elseif (isset($_POST['un']) && isset($_POST['pw']))
{
    // User is logging in...
    $user = strtolower($_POST['un']);
    $hashed_pass = md5($_POST['pw']);
    checkLogin($user, $hashed_pass);
    if (isset($_POST['rem']))
    { // If user ticked 'Remember Me' while logging in
        $t = time() + 86400 * 365;
    }
    else
    {
        $t = 0;
    }
    setcookie('FBMPGPLogin', $cookie, $t);
    if ($loggedIn) setcookie('FBMPGPUserID', $userId, $t);
}
elseif (isset($_POST['suun']))
{
    require_once ('includes/signup.php');
}
elseif (isset($_GET['verify']) && ($_GET['email']) && !empty($_GET['email']) and isset($_GET['hash']) && !empty($_GET['hash']) and isset($_GET['username']) && !empty($_GET['username']))
{
    $email = $_GET['email']; // Set email variable
    $hashString = explode("-", $_GET['hash']);
    $hash = $hashString[0];
    $hashed_pass = $hashString[1];
    $username = $_GET['username'];
    checkLogin($username, $hashed_pass, 0);
}
elseif (isset($_COOKIE['FBMPGPLogin']))
{
    // Authorization Check
    $cookie = base64_decode($_COOKIE['FBMPGPLogin']);
    if (isset($_COOKIE['FBMPGPUserID'])) $uid = $_COOKIE['FBMPGPUserID'];
    else  $uid = 0;
    $cookie = base64_decode($_COOKIE['FBMPGPLogin']);
    list($user, $hashed_pass) = explode(':', $cookie);
    checkLogin($user, $hashed_pass, $uid);
}
else
{
    // No authorization found. Show login box
    showLogin();
}
// Now the user must be logged in already for the below code to be executed
execComponent('userLoggedIn');
// Access Token Checking
if ($adminOptions['emailVerify'] && $userOptions['emailSent'] && !$userOption['emailVerified'])
{
    showHTML($lang['Email Not Verified'], $lang['Welcome'] . " $userName");
}
elseif ($userOptions['userDisabled'])
{
    showHTML($userOptions['disableReason'] . "<br />" . $lang['Manual approval'], $lang['Welcome'] . " $userName");
}
elseif (isset($_POST['fbuid']) && isset($_POST['fbpw'])) {
    require_once ('includes/tptoken.php');
}
elseif (!isset($_POST['token']) && !isset($_GET['ucp']))
{
    require_once ('includes/fbtoken.php');
}
// Is this a Page/Groups Refresh Data Request?
if (isset($_GET['rg']) || isset($_POST['upGroups']))
{
    require_once ('includes/fbrg.php');
}
// Is this a Post Preset Save submission?
if (isset($_POST['pageid']))
{
    if (isset($_POST['savename']))
    {
        if (($_POST['pageid'] == 0) && ($_POST['savename'] !== ''))
        {
            require_once ('includes/savepost.php');
        }
    }
}
// Is this a logged in user show help/documentation request?
if (isset($_GET['usershowhelp']))
{
    showHelp();
}
elseif (isset($_GET['ucp']))
{
    //User Control Panel request?
    require_once ('includes/usercp.php');
}
elseif (isset($_GET['crons']))
{
    require_once ('includes/showcrons.php');
}
elseif (isset($_GET['logs']))
{
    require_once ('includes/showlogs.php');
}
// Now we have all the data as user is logged into us
$pages = explode("\n", urldecode($pageData));
$groups = explode("\n", urldecode($groupData));
$isGroupPost = false;
if (isset($_POST['pageid']))
{
    // Is this a Post Preset Save submission?
    if (isset($_POST['savename']))
    {
        if (($_POST['pageid'] == 0) && ($_POST['savename'] !== ''))
        {
            savePost();
        }
    }
    // This is a post submission. Time to actually post this submission to selected account.
    require_once ('includes/post.php');
}
else
{
    // No pageid means not a post request, just show the fields and forms to fill-up
    require_once ('includes/mainform.php');
    require_once ('includes/class.JavaScriptPacker.php');
    $message = sanitizeOutput($message);
    $packer = new JavaScriptPacker($script, 10, true, false);
    $script = $packer->pack(); // We encrypt the javascript output to make copying difficult on public sites
    $message .= $script . '</script> ';
    showHTML($message, "<div class='row flex-align-center'><div class=stub><img height=64 src='http://graph.facebook.com/" . $GLOBALS['__FBAPI__'] . "/$userId/picture?redirect=1&height=64&type=normal&width=64' class='cell'></div><div class=cell>" . $lang['Welcome'] . " $fullname</div></div>");
}
?>