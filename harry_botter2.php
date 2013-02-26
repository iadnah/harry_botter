#!/usr/bin/env php
<?php
/*
harry_botter is an IRC bot written in PHP.
author: Iadnah - iadnah@uplinklounge.com
Bot features include:
	- automatic opping, hopping, voicing, and kicking
	- stores user data in a sql database
	- !seen command to show last time a user was in the channel and said something
	- various commands for generating string checksums and hashes
	- stores list of online users in a database for easy querying for a web page


Sample code for accessing the database (this is php):

-----cut-----
mysql_connect('your_database_ip_here','database_username_here','database_password_here');
@mysql_select_db(database_name_here) or die("Could not connect to database.");
$query = "select * from seenlog where online = \"1\"";
$result = mysql_query($query);
$num = mysql_numrows($result);
$x=0;

while ($x < $num) {
	$nick = mysql_result($result,$x,"nick");
	$x++;
	echo "$nick";
}
------cut--------

If you just include the above in a page and change the strings like it says it will output a list of the all the
people who are in the channel at the moment.


The actual structure of the database is just two tables as follows (this is output from the describe command
in MySQL:
describe seenlog;
+--------+---------+------+-----+---------+----------------+
| Field  | Type    | Null | Key | Default | Extra          |
+--------+---------+------+-----+---------+----------------+
| id     | int(11) | NO   | PRI | NULL    | auto_increment |
| nick   | text    | YES  |     | NULL    |                |
| last   | int(11) | YES  |     | NULL    |                |
| online | int(11) | YES  |     | NULL    |                |
+--------+---------+------+-----+---------+----------------+

describe acl;
+------------+---------+------+-----+---------+----------------+
| Field      | Type    | Null | Key | Default | Extra          |
+------------+---------+------+-----+---------+----------------+
| id         | int(11) | NO   | PRI | NULL    | auto_increment |
| nick       | text    | YES  |     | NULL    |                |
| op         | int(11) | YES  |     | NULL    |                |
| voice      | int(11) | YES  |     | NULL    |                |
| kick       | int(11) | YES  |     | NULL    |                |
| friend     | int(11) | YES  |     | NULL    |                |
| enemy      | int(11) | YES  |     | NULL    |                |
| pass       | text    | YES  |     | NULL    |                |
| authed     | int(11) | YES  |     | NULL    |                |
| ereason    | text    | YES  |     | NULL    |                |
| hostmask   | text    | YES  |     | NULL    |                |
| login_time | text    | YES  |     | NULL    |                |
| failed     | int(11) | YES  |     | NULL    |                |
+------------+---------+------+-----+---------+----------------+


This software is released under the MIT License.

The MIT License

Copyright (c) 2006 iadnah

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated 
documentation files (the "Software"), to deal in the Software without restriction, including without limitation the 
rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to 
permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the 
Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE 
WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS 
OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR 
OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

*/


/* Variables that determine server, channel, etc */
$CONFIG = array();
$CONFIG['server'] = ''; // IP or hostname of irc server to connect to. ssl:// for an ssl connection
$CONFIG['nick'] = 'harry_botter'; // nick (name) to use
$CONFIG['port'] = 6667; // port (standard: 6667)
$CONFIG['channel'] = '#uplinklounge'; // channel (chatroom) to join
$CONFIG['name'] = 'harry'; // ident name
$CONFIG['nickserv'] = ''; //nickserv password
$CONFIG['database'] = '';     //mysql database to connect to
$CONFIG['db_username'] = ''; //username for above database
$CONFIG['db_password'] = ''; //password for above database
$CONFIG['db_host'] = ''; //hostname where database is located
$CONFIG['log'] = 'uplink.log'; //file to log channel talk to
$CONFIG['max_log_size'] = '1048576'; //max log file size. doesn't work right yet.

//On some IRC servers a few of the functions harry has require IRC op access.
$CONFIG['oper'] = '';
$CONFIG['oper_pass'] = '';

$users = array();
$count = 0;

/* Let it run forever (no timeouts) */
set_time_limit(0);

/* The connection */
$con = array();
$old_buffer = array();

/* start the bot... */
init();

function init()
{
    $name_counter = 0;
    $name_threshold = 20;

    global $con, $CONFIG, $buffer;
       db_connect();
    
    /* Connect to the irc server */
    $con['socket'] = fsockopen($CONFIG['server'], $CONFIG['port']);
    
    /* Check that we have connected */
    if (!$con['socket']) {
        print ("Could not connect to: ". $CONFIG['server'] ." on port ". $CONFIG['port']);
    } else {
        /* Send the username and nick */

        cmd_send("USER ". $CONFIG['nick'] ." uplinklounge.com uplinklounge.com :". $CONFIG['name']);
        cmd_send("NICK ". $CONFIG['nick'] ." uplinklounge.com");
        cmd_send("JOIN ". $CONFIG['channel']);
        cmd_send("PRIVMSG nickserv :IDENTIFY ". $CONFIG['nickserv']);
			cmd_send("OPER ". $CONFIG['oper'] ." ". $CONFIG['oper_pass']);

        /* Here is the loop. Read the incoming data (from the socket connection) */
        while (!feof($con['socket']))
        {
		$con['buffer']['all'] = trim(fgets($con['socket'], 4096));
		print date("[d/m @ H:i]")."<- ".$con['buffer']['all'] ."\n";
            
		//If the server pings us, reply so we don't get kicked.
		if(substr($con['buffer']['all'], 0, 6) == 'PING :') {
			cmd_send('PONG :'.substr($con['buffer']['all'], 6));

            	} 
		elseif ($old_buffer != $con['buffer']['all']) {
	                //Log to log file specified in $CONFIG['log']
	                //This file must exist already
	                log_to_file($con['buffer']['all']);
                
	                // make sense of the buffer
	                parse_buffer();
                
	                // now process any commands issued to the bot
	                process_commands();
	        }
		else {
			cmd_send("JOIN ". $CONFIG['channel']);
		}

		//check to see who is actually in the channel every so often
		//the reply is then parsed and then seenlog is updated

		if ($name_counter == $name_threshold) {
	                cmd_send("NAMES ". $CONFIG['channel']);
	                $name_counter = 0;
		}
		$name_counter++;

		$old_buffer = $con['buffer']['all'];
        }
//	init();
    }
}

//sends a raw irc command to the server and prints output to the console
function cmd_send($command)
{
    global $con, $time, $CONFIG;
    fputs($con['socket'], $command."\n\r");
    print (date("[d/m @ H:i]") ."-> ". $command. "\n\r");
}

function torcheck($host) {
        $ip = gethostbyname($host);

        //reverse octets
        $x = explode('.', "$ip");
        $rip = $x[3]. '.'. $x[2]. '.'. $x[1]. '.'. $x[0];

        $name = "$rip.tor.ahbl.org";
        $f = gethostbyname($name);
        if (strstr("$f", '127.0.0')) {
		return 'tor';
        }
}


function getip($nick) {
        $nick = trim($nick);
        $result = whois("$nick");

        foreach ($result as $line) {
                $line = rtrim($line);
                if (strstr("$line", 'is actually')) {
                        $output = substr("$line", strpos("$line", 'is actually'));
			$x = explode(' ', $output);
			$c = count($x) - 1;
			$ip = $x[$c];
			$ip = str_replace('[', '', "$ip");
			$ip = trim(str_replace(']', '', "$ip"));
			return "$ip";
                }
        }
}




function log_to_file ($data)
{
    global $CONFIG;
    $filename = $CONFIG['log'];
    $data .= "\n";
    // open the log file
    if ($fp = fopen($filename, "a+"))
    {
        if ((fwrite($fp, $data) === FALSE))
        {
            echo "Could not write to file.\n>";
        }

    }
    else
    {
        echo "File could not be opened.\n";
    }
}

//Connect to the MySQL database. Harry just uses an ongoing persistent connection. I ran into
//no problems with this when testing him.
function db_connect()
{
    global $CONFIG;
        mysql_connect($CONFIG['db_host'],$CONFIG['db_username'],$CONFIG['db_password']);
        @mysql_select_db($CONFIG['database']) or die("Could not connect to database.");
}

function process_commands()
{
    global $con, $CONFIG, $buffer;
    
    //Set text from the buffer and then use a switch to figure out what to do.
       $args = explode(" ", $buffer['text']);
    $text = strtoupper($args[0]);
    if (($text{0} == ".") || ($text{0} == "!")) {

    $text = substr($text, 1);
    $username = $con['buffer']['username'];
    $hostmask = $buffer['user_host'];
    $query="select * from acl where nick = \"$username\"";
    $result=mysql_query($query);
    $num=mysql_numrows($result);
    if ($num != "0") {
        $enemy = mysql_result($result,0,"enemy");
        $dbhostmask = mysql_result($result,0,"hostmask");
        $authed = mysql_result($result,0,"authed");

        if ("$hostmask" == "$dbhostmask") {
            if ($authed == "1") {
                $authed = "yes";

	        $op = mysql_result($result,0,"op");
	        $voice = mysql_result($result,0,"voice");
	        $kick = mysql_result($result,0,"kick");
           }
            else {
                $authed = "no";

		$op = 0;
		$voice = 0;
		$kick = 0;
            }
        }
        else {
            $authed = "no";
        }    
    }
    else {
        $op = 0;
        $voice = 0;
        $kick = 0;
    }

    switch ($text) {

    case "HMAC":
        $algo = strtoupper($args[1]);
        $key = trim($args[2]);
        $x = 3;
        $arcount = count($args);
        while ($x < $arcount) {
            $string = "$string ". $args[$x];
            $x++;
        }
        $string = ltrim($string);
        $string = rtrim($string);

        switch ($algo) {
            case "MD5":
                $hash = bin2hex(mhash(MHASH_MD5, $string, $key));
                break;
            case "RIPEMD128":
                $hash = bin2hex(mhash(MHASH_RIPEMD128, $string, $key));
                break;
            case "RIPEMD160":
                $hash = bin2hex(mhash(MHASH_RIPEMD160, $string, $key));
                break;
            case "MD4":
                $hash = bin2hex(mhash(MHASH_MD4, $string, $key));
                break;
            case "SHA1":
                $hash = bin2hex(mhash(MHASH_SHA1, $string, $key));
                break;
            case "SHA256":
                $hash = bin2hex(mhash(MHASH_SHA256, $string, $key));
                break;
            case "SHA384":
                $hash = bin2hex(mhash(MHASH_SHA384, $string, $key));
                break;
            case "SHA512":
                $hash = bin2hex(mhash(MHASH_SHA512, $string, $key));
                break;
            case "WHIRLPOOL":
                $hash = bin2hex(mhash(MHASH_WHIRLPOOL, $string, $key));
                break;
            case "TIGER128":
                $hash = bin2hex(mhash(MHASH_TIGER128, $string, $key));
                break;
            case "TIGER160":
                $hash = bin2hex(mhash(MHASH_TIGER160, $string, $key));
                break;
            case "TIGER192":
                $hash = bin2hex(mhash(MHASH_TIGER192, $string, $key));
                break;
            case "ADLER32":
                $hash = bin2hex(mhash(MHASH_ADLER32, $string, $key));
                break;
            case "CRC32":
                $hash = bin2hex(mhash(MHASH_CRC32, $string, $key));
                break;
            case "GOST":
                $hash = bin2hex(mhash(MHASH_GOST, $string, $key));
                break;
            case "HAVAL128":
                $hash = bin2hex(mhash(MHASH_HAVAL128, $string, $key));
                break;
            case "HAVAL160":
                $hash = bin2hex(mhash(MHASH_HAVAL160, $string, $key));
                break;
            case "HAVAL192":
                $hash = bin2hex(mhash(MHASH_HAVAL192, $string, $key));
                break;
            case "HAVAL256":
                $hash = bin2hex(mhash(MHASH_HAVAL256,3, $string, $key));
                break;
            default:
                $algo = "Error";
                $hash = "I don't know of any algorithm by that name.";
                break;
            }
    
            cmd_send(prep_text("$algo", " $hash"));
         break;

    case "HASH":
        $x = 1;

        
        if (strtoupper($args[1]) == "PRIVATE") {
            $private = "1";
            $x++;
        }

        $algo = strtoupper($args[$x]);
        $x++;
        $arcount = count($args);
        while ($x < $arcount) {
            $string = "$string ". $args[$x];
            $x++;
        }
        $string = ltrim($string);
        $string = rtrim($string);

        switch ($algo) {
            case "MD5":
                $hash = bin2hex(mhash(MHASH_MD5, $string));
                break;
            case "RIPEMD128":
                $hash = bin2hex(mhash(MHASH_RIPEMD128, $string));
                break;
            case "RIPEMD160":
                $hash = bin2hex(mhash(MHASH_RIPEMD160, $string));
                break;
            case "MD4":
                $hash = bin2hex(mhash(MHASH_MD4, $string));
                break;
            case "SHA1":
                $hash = bin2hex(mhash(MHASH_SHA1, $string));
                break;
            case "SHA256":
                $hash = bin2hex(mhash(MHASH_SHA256, $string));
                break;
            case "SHA384":
                $hash = bin2hex(mhash(MHASH_SHA384, $string));
                break;
            case "SHA512":
                $hash = bin2hex(mhash(MHASH_SHA512, $string));
                break;
            case "WHIRLPOOL":
                $hash = bin2hex(mhash(MHASH_WHIRLPOOL, $string));
                break;
            case "TIGER128":
                $hash = bin2hex(mhash(MHASH_TIGER128, $string));
                break;
            case "TIGER160":
                $hash = bin2hex(mhash(MHASH_TIGER160, $string));
                break;
            case "TIGER192":
                $hash = bin2hex(mhash(MHASH_TIGER192, $string));
                break;
            case "ADLER32":
                $hash = bin2hex(mhash(MHASH_ADLER32, $string));
                break;
            case "CRC32":
                $hash = bin2hex(mhash(MHASH_CRC32, $string));
                break;
            case "GOST":
                $hash = bin2hex(mhash(MHASH_GOST, $string));
                break;
            case "HAVAL128":
                $hash = bin2hex(mhash(MHASH_HAVAL128, $string));
                break;
            case "HAVAL160":
                $hash = bin2hex(mhash(MHASH_HAVAL160, $string));
                break;
            case "HAVAL192":
                $hash = bin2hex(mhash(MHASH_HAVAL192, $string));
                break;
            case "HAVAL256":
                $hash = bin2hex(mhash(MHASH_HAVAL256,3, $string));
                break;
            case "BASE64":
                $hash = base64_encode($string);
                break;

        }
    
        if ($private == "1") {
        cmd_send(prep_ptext("$algo", " $hash"));
        }
        else {
        cmd_send(prep_text("$algo", " $hash"));
        }
        break;

    case "TIME":
        cmd_send(prep_text("Time", date("F j, Y, g:i a", time())));
       break;

    case "SEEN":
        $thisuser = $args[1];
        $query = "select last from seenlog where nick = \"$thisuser\"";
        $result = mysql_query($query);
        $num = mysql_numrows($result);
        echo "$query\n\nNum Results: $num\n\n";

        if ($num > 0) {
            $last = mysql_result($result,0,"last");
            echo "$last\n\n";
            $last = date("r", $last);
            
            $string = "Last time I saw $thisuser was $last";;
            cmd_send(prep_text("Seen", " $string"));
        }
        else {
            cmd_send(prep_text("Seen", " The user $thisuser is not in my records."));
        }

        break;


    case "KICK":
     if ("$authed" != "yes") {
        cmd_send(prep_ptext("Error", " You're not authenticated."));
        break;
     }
       if (($kick == "1") || ($op >= "1")) {
        if (isset($args[2])) {
            $arcount = count($args);
            $x = 2;

            while ($x < $arcount) {
                $reason .= $args[$x]. " ";
                $x++;
            }
        }
        else {
            $reason = "Just be glad I'm not Porthos.";
        }
           cmd_send("KICK ". $CONFIG['channel']. " ". $args[1]. " :". "$reason");
     }
       break;

    case "SETPASSWD":
     echo "We actually got this far!\n";
     if ("$authed" != "yes") {
        cmd_send(prep_ptext("Error", " You're not authenticated."));
        break;
     }
     if ($op >= "5") {
        $target = $args[1];
        $pass = $args[2];
        $cpass = bin2hex(mhash(MHASH_SHA512, $pass));
        $query = "update acl set pass = \"$cpass\" where nick = \"$target\"";
        $result = mysql_query($query);

        cmd_send(prep_ptext("Notice", " $target's password successfully changed to $pass."));
     }
     else {
        cmd_send(prep_ptext("Error", " You are only op level $op."));
     }
     break;

    case "PASSWD":
     if ("$authed" != "yes") {
        cmd_send(prep_ptext("Error", " You're not authenticated."));
        break;
     }
     else {
        $oldpass = $args[1];
        $oldcpass = bin2hex(mhash(MHASH_SHA512, $oldpass));
        $newpass = trim($args[2]);
        $newcpass = bin2hex(mhash(MHASH_SHA512, $newpass));

         $query="select pass from acl where nick = \"$username\"";
        $result = mysql_query($query);
        $dbpass = mysql_result($result,0,"pass");
    
        if ("$oldcpass" === "$dbpass") {
            $query = "update acl set pass = \"$newcpass\" where nick = \"$username\"";
            $result = mysql_query($query);
            cmd_send(prep_ptext("Notice", " Password successfully changed to $newpass."));
         }
        else {
            cmd_send(prep_ptext("Error", " The syntax is .passwd <oldpassword> <newpassword>"));
        }
     }
     break;


    case 'WHOIS':
        $nick = trim($args[1]);
	$result = whois("$nick");

	foreach ($result as $line) {
		$line = rtrim($line);
		cmd_send(prep_ptext("WHOIS", " $line"));
	}
	break;

    case 'REALIP':
	$nick = trim($args[1]);
	$result = whois("$nick");

	foreach ($result as $line) {
		$line = rtrim($line);
		if (strstr("$line", 'is actually')) {
			$output = substr("$line", strpos("$line", 'is actually'));
			cmd_send(prep_ptext("REAL IP", " $nick $output"));
		}	
	}
	break;

    case "AUTH":
        $query="select pass, failed, login_time, op from acl where nick = \"$username\"";
        echo "$query\n";
        $result = mysql_query($query);
        $pass = mysql_result($result,0,"pass");
        $tries = mysql_result($result,0,"failed");
        $last = mysql_result($result,0,"login_time");
        $cpass = trim($args[1]);
        $dbop = mysql_result($result,0,"op");

        $time = date("F j, Y, g:i a", time());

        if (bin2hex(mhash(MHASH_SHA512, $cpass)) === "$pass") {
            $query = "update acl set authed = \"1\", hostmask = \"$hostmask\", login_time = \"$time\", failed = \"0\" where nick = \"$username\"";
            $result = mysql_query($query);
            cmd_send(prep_ptext("Login Successful", " Your last login was on $last."));

            if ($tries > 0) {
                cmd_send(prep_ptext("Alert", " There have been $tries failed login attempts since your last login."));
            }

            if ($dbop == "1") {
                            cmd_send("MODE ". $CONFIG['channel']. " +v :$username");
            }
            elseif ($dbop == "2") {
                            cmd_send("MODE ". $CONFIG['channel']. " +h :$username");
            }
            elseif ($dbop >= "3") {
                            cmd_send("MODE ". $CONFIG['channel']. " +o :$username");
            }

        }
        else {
            $tries++;
            $query = "update acl set failed = \"$tries\" where nick = \"$username\"";
            $result = mysql_query($query);
        }
        break;

    case "LOGOUT":
        $query="update acl set authed = \"0\" where nick = \"$username\"";
        $result = mysql_query($query);
        break;

    case "MOD":
       if ($op >= "1") {
          cmd_send("MODE ". $CONFIG['channel']. "+m");
          cmd_send(prep_text("Moderated", " This channel is now moderated. Only people with voice or op can talk."));
       }
       break;

    case "VOICEALL":
        if (($voice == "1") || ($op >= "1")) {
                $query = "select * from seenlog where online = \"1\"";
                $result = mysql_query($query);
                $num = mysql_numrows($result);
                $x=0;
                while ($x < $num) {
                    $nick = mysql_result($result,$x,"nick");

                        if ($nick{0} == '~') {
                                $nick = substr($nick, 1);
                        }
                        elseif ($nick{0} == '+') {
                        $nick = substr($nick, 1);
			}
                        $nicks[] = "$nick";
                        $x++;
                }

		foreach ($nicks as $thisnick) {
                	cmd_send("MODE ". $CONFIG['channel']. " +v :$thisnick");
		}
        }
        break;


    case "SSL":
	 if ($op >= "2") {

	$query = "select * from seenlog where online = \"1\"";
	$result = mysql_query($query);
	$num = mysql_numrows($result);
	$x=0;

	while ($x < $num) {
	    $nick = mysql_result($result,$x,"nick");

		if ($nick{0} == '~') {
		        $nick = substr($nick, 1);
		}
		elseif ($nick{0} == '+') {
		        $nick = substr($nick, 1);
		}

	    $whois = whois($nick);

	    foreach ($whois as $line) {
		if (strstr("$line", '(SSL)')) {
			$ssl = 1;
			break;
		}
	    }

	    if ($ssl != 1) {
		cmd_send("KICK ". $CONFIG['channel']. " $nick :". "Channel is going SSL only.");
	    }

	    unset($ssl);

	    $x++;
	}

	 cmd_send("MODE ". $CONFIG['channel']. "+S");
	 cmd_send(prep_text("Secure Line", " Attempting to set channel to SSL only mode."));
	 $config['ssl_on'] = 1;
	 }
        break;

    case "DESSL":
         if ($op >= "2") {
		 $config['ssl_on'] = 0;
                 cmd_send("MODE ". $CONFIG['channel']. "-S");
                 cmd_send(prep_text("Insecure Line", " Attempting to remove SSL only mode."));
         }
        break;

    case "DEMOD":
          if ($op >= "1") {
              cmd_send("MODE ". $CONFIG['channel']. " -m");
           }
           break;    

    case "OP":
        if ($op >= "3") {
            cmd_send("MODE ". $CONFIG['channel']. " +o :" .$args[1]);
        }
        break;
        case "HOP":
                if ($op >= "2") {
                $x=1;
                while ($x < count($args)) {
                        cmd_send("MODE ". $CONFIG['channel']. " +v :" .$args[$x]);
                        $x++;
                }
	        }
                break;

    case "DEOP":
                if ($op >= "3") {
            if ($args[1] != $CONFIG['nick']) {
		$x=1;
		while ($x < count($args)) {
                        cmd_send("MODE ". $CONFIG['channel']. " -o :" .$args[$x]);
			$x++;
		}
            }
                }
                break;
        case "DEHOP":
                if ($op >= "2") {
                $x=1;
                while ($x < count($args)) {
                        cmd_send("MODE ". $CONFIG['channel']. " -h :" .$args[$x]);
                        $x++;
                }
        	}
	        break;
    case "VOICE":
        if (($voice == "1") || ($op >= "1")) {
		$x=1;
		while ($x < count($args)) {
                        cmd_send("MODE ". $CONFIG['channel']. " +v :" .$args[$x]);
			$x++;
		}
        }
                break;
    case "DEVOICE":
	if (($voice == "1") || ($op >= "1")) {
                $x=1;
                while ($x < count($args)) {
                        cmd_send("MODE ". $CONFIG['channel']. " -v :" .$args[$x]);
                        $x++;
                }
                }
                break;

    case "SHOWACL":
       if ($authed != "yes") {
        cmd_send(prep_ptext("Error", " You must be logged in to use that command."));
            break;
           }

       if (isset($args[1])) {
        $target = addslashes($args[1]);
       }
       else {
        $target = $username;
       }

            $query="select * from acl where nick = \"$target\"";
            $result=mysql_query($query);
               $num=mysql_numrows($result);
            if ($num > 0) {
                   $top = mysql_result($result,0,"op");
                   $tvoice = mysql_result($result,0,"voice");
                   $tkick = mysql_result($result,0,"kick");
                $tenemy = mysql_result($result,0,"enemy");
                $tfriend = mysql_result($result,0,"friend");
                $treason = mysql_result($result,0,"ereason");
                $string = "$target has ";
                if ($top > 0) {
                    $string .= "Op level $top ";
                }
                if ($tvoice == 1) {
                    $string .= "ability to voice ";
                }
                if ($tkick == 1) {
                    $string .= "ability to kick ";
                }
                if ($tenemy > 0) {
                    $string = "$target is my enemy. Reason: $treason.";
                }

                cmd_send(prep_ptext("ACL Info", " $string"));
            }
            else {
                cmd_send(prep_ptext("ACL Info", " $target is not in my records."));
            }
    
            break;
    case "ACL":
       if (isset($args[2])) {
            $username = $args[2];
            }
        else { 
            $username = $con['buffer']['username'];
        }
        
        if ($args[1] == "grant") {
            if ($op >= "1") {
                $priv = $args[3];
                $privarg = $args[4];
                $query="select nick from acl where nick = \"$username\"";
                $result=mysql_query($query);
                $num=mysql_numrows($result);
                
                if ($num > "0") {
                    $updating="yes";
                    $query="update acl set";
                }
                else {
                    $query="insert into acl set nick = \"$username\", friend = \"1\", enemy = \"0\",";

                }
                
                if ($priv == "enemy") {
                    $arcount = count($args);
                    if (isset($args[4])) {
                        $elevel = addslashes($args[4]);
                    }
                    else {
                        $elevel = "1";
                    }
                    
                    $cycle = 5;
                    while ($cycle < $arcount) {
                        $ereason = $ereason. " ". $args[$cycle];
                        $cycle++;
                    }
                    $rmstring = "friend = \"0\", op = \"0\", voice = \"0\", kick = \"0\"";
                    if ($updating == "yes") {
                        $query="$query $rmstring, enemy = \"$elevel\", ereason = \"$ereason\" where nick = \"$username\""; 
                    }
                    else {
                        $query="insert into acl set nick = \"$username\", $rmstring, enemy = \"$elevel\", ereason = \"$ereason\"";
                    }
                    cmd_send(prep_text("ACL Update", " $username is now my enemy (level $elevel)."));
                }
                elseif ($priv == "op") {
                    if (isset($args[4])) {
                        $oplevel = $args[4];
                        if ($oplevel > $op) {
                            $oplevel = "$op";
                            cmd_send(prep_ptext("Error", " You can't give someone higher op level than you have. $username now has op level $oplevel"));
                        }
                    }
                    else {
                        $oplevel = "1";
                    }
                    $query = "$query op = \"$oplevel\", voice = \"1\", kick = \"1\"";
                    cmd_send(prep_text("ACL Update", " $username has been granted the ability to op, kick, and voice."));
                }
                elseif ($priv == "voice") {
                    $query = "$query voice = \"1\"";    
                    cmd_send(prep_text("ACL Update", " $username has been granted the ability to voice."));
                }
                elseif ($priv == "kick") {
                    $query = "$query kick = \"1\"";
                    cmd_send(prep_text("ACL Update", " $username has been granted the ability to kick."));
                }
                elseif ($updating == "yes") {
                    $query = "$query where nick = \"$username\"";
                }
            
                echo "\n\n$query\n\n";
                $result=mysql_query($query);
            }
            else {
                cmd_send(msg_text("Only ops can do that asshole."));
            }
        }
        
        if ($args[1] == "revoke") {
            if ($op >= "5") {
                $priv = $args[3];
                $query="select nick from acl where nick = \"$username\"";
                $result=mysql_query($query);
                $num=mysql_numrows($result);
        
                if ($num > "0") {
                    $updating="yes";
                    $query="update acl set";
                }
                else {
                    $query="insert into acl set nick = \"$username\"";
                }
                if ($priv == "op") {
                    $query = "$query op = \"0\"";
                    cmd_send(prep_text("ACL Update", " $username has been stripped of their OP magic!"));
                }
                if ($priv == "voice") {
                    $query = "$query voice = \"0\"";
                    cmd_send(prep_text("ACL Update", " $username may not give out voice anymore."));
                }
                if ($priv == "kick") {
                    $query = "$query kick = \"0\"";
                    cmd_send(prep_text("ACL Update", " $username can't kick anybody now."));
                }
                if ($priv == "enemy") {
                    $query = "$query kick = \"0\", voice = \"0\", op = \"0\", friend = \"0\", enemy = \"1\"";
                }
                
                if ($updating == "yes") {
                    $query = "$query where nick = \"$username\"";
                }
                
                $result=mysql_query($query);
                }
            else {
                cmd_send(msg_text("Only ops can do that asshole."));
            }
        }        
        break;
    case "HELP";
        cmd_send(msg_text("I'm Harry Botter, and my magic will amaze you!"));
        cmd_send(msg_text("=============================================="));
        cmd_send(msg_text("Basic Commands (everybody can use these):"));
        cmd_send(msg_text("   .HELP : This is what you're seeing right now."));
        cmd_send(msg_text("   .TIME : I'll use my magic to tell you the local time."));
        cmd_send(msg_text("   .showacl : I'll tell you what access rights you have, if any."));
        cmd_send(msg_text("   .showacl <username>: I'll tell you what access rights someone else has."));
        cmd_send(msg_text("   .hash <algo> <text>: I'll generate the hash of the text you give me. Supported hashes include md4, md5, sha1, sha256, sha512, ripemd160, and many others."));
	cmd_send(msg_text("   .auth <password>: This is how you authenticate with me. You can only use certain commands when logged in."));
	cmd_send(msg_text("   .passwd <old pass> <new pass>: This is how you change your password for me."));
        cmd_send(msg_text("Commands for my friends:"));
        cmd_send(msg_text("   .OP or .HOP <nick> : I'll op/hop who you tell me to. .DEOP and .DEHOP work as well."));
        cmd_send(msg_text("   .VOICE <nick> : I'll give voice to who you say. DEVOICE works too, of course."));
        cmd_send(msg_text("   .KICK <nick> : I'll kick them from the channel."));
        cmd_send(msg_text("   .SSL <nick> : I'll set it so only people connected over SSL can join the channel. Undo this with DESSL."));
        cmd_send(msg_text("   .MOD <nick> : I'll set mode +m so only people with voice or ops can talk. .DEMOD undoes this."));

        break;
    }
    }
}

function whois($nick) {
    global $con, $time, $CONFIG;
    fputs($con['socket'], "whois $nick\n\r");
    $loop=1;
    while ($loop == 1) {
     $output = fgets($con['socket'], 1024);

     echo "$output\n";

     $whois[] = $output;
     if (strstr("$output", ':End of /WHOIS list.')) {
	echo "Match found.\n";
	$loop = 0;
     }
    }
    return $whois;
}


function parse_buffer()
{
    global $con, $CONFIG, $users, $count, $buffer;
        
    $buffer = $con['buffer']['all'];
    $buffer = explode(" ", $buffer, 4);
    

    /* Get username */
    $buffer['username'] = substr($buffer[0], 1, strpos($buffer['0'], "!")-1);
    
    /* Get identd */
    $posExcl = strpos($buffer[0], "!");
    $posAt = strpos($buffer[0], "@");
    $buffer['identd'] = substr($buffer[0], $posExcl+1, $posAt-$posExcl-1); 
    $buffer['hostname'] = substr($buffer[0], strpos($buffer[0], "@")+1);
    
    /* The user and the host, the whole shabang */
    $buffer['user_host'] = substr($buffer[0],1);
    
    /* Isolate the command the user is sending from
    the "general" text that is sent to the channel
    This is  privmsg to the channel we are talking about.
    
    We also format $buffer['text'] so that it can be logged nicely.
    */
    switch (strtoupper($buffer[1]))
    {
        case "JOIN":
            $buffer['text'] = "*JOINS: ". $buffer['username']." ( ".$buffer['user_host']." )";
            $buffer['command'] = "JOIN";
            $buffer['channel'] = $CONFIG['channel'];

            $user = $buffer['username'];


	    $ip = getip($user);
	
	    $tor = torcheck($ip);

	    if ("$tor" == 'tor') {
		cmd_send("KILL $user : You may not come here using Tor proxies.");
		echo "KILLED $user from $ip for using Tor.\n";
	    }

	    if ($config['ssl_on'] == 1) {
	            if ($nick{0} == '~') {
		            $user = substr($user, 1);
	            }
	            elseif ($user{0} == '+') {
	 	           $user = substr($user, 1);
	            }
	
	            $whois = whois($user);
	
	            foreach ($whois as $line) {
	                if (strstr("$line", '(SSL)')) {
	                        $ssl = 1;
	                        break;
	                }
	            }
	
	            if ($ssl != 1) {
	                cmd_send("KICK ". $CONFIG['channel']. " $user :". "Channel is currently for SSL users only. Come back later.");
	            }
	
	            unset($ssl);
	    }

            $query="select * from acl where nick = \"$user\"";
            $result = mysql_query($query);
            $num = mysql_numrows($result);
            
            if (0 < $num) {
                $enemy_status = mysql_result($result,0,"enemy");
                $ereason = mysql_result($result,0,"ereason");
                $query = "update acl set authed = \"0\" where nick = \"$user\"";
                $result = mysql_query($query);

                switch ($enemy_status) {
                    case "1":
                        if ($ereason == "") {
                            $ereason == "You should know better than to come in here, you bloody wanker!";
                        }
                        cmd_send(prep_text("$user", " $ereason"));
                        break;
                    case "2":
                        if ($ereason == "") {
                            $ereason == "You should know better than to come in here, you bloody wanker!";
                        }
                        cmd_send("KICK ". $CONFIG['channel']. " $user :". "$ereason");
                           break;
                }
            }
            break;

        case "353":
                $usarg = explode(" ", $buffer[3]);
            
            $uscount = count($usarg);

            $y = 2;            
            
                        $query = "update seenlog set online = \"0\"";
                        $result = mysql_query($query);

            while ($y < $uscount) {
                $thisuser = $usarg[$y];

                if ($thisuser{0} == "~") {
                    $thisuser = substr($thisuser, 1);
                }

                                if ($thisuser{0} == ":") {
                                        $thisuser = substr($thisuser, 1);
                                }

                if ($thisuser{0} == "@") {
                                        $thisuser = substr($thisuser, 1);
                                }

                if ($thisuser{0} == "%") {
                                        $thisuser = substr($thisuser, 1);
                                }
                

                            $time = date("U");
                            $query = "select * from seenlog where nick = \"$thisuser\"";

                            $result = mysql_query($query);
                            $num = mysql_numrows($result);
                            if ($num == 1) {
                                    $query = "update seenlog set last = \"$time\", online = \"1\" where nick = \"$thisuser\"";
                                    $result = mysql_query($query);
    
                            }
                            else {
                                    echo "$user is not in my records, must add them.\n";
                                    $query = "insert into seenlog set nick = \"$thisuser\", last = \"$time\", online = \"1\"";
                                    $result = mysql_query($query);
                            }



                $y++;
            }
            $query = "select nick, online from seenlog where online = \"0\"";
            $result = mysql_query($query);
            $unum = mysql_numrows($result);
            $counter = 0;

            while ($counter < $unum) {
                $nick = mysql_result($result,$counter,"nick");
                $tquery = "select nick from acl where nick = \"$nick\"";
                $tres = mysql_query($tquery);
                $count = mysql_numrows($tres);
                if (0 < $count) {
                    $uquery = "update acl set authed = \"0\" where nick = \"$nick\"";
                    mysql_query($uquery);
                }
                $counter++;
            }

            break;

        case "QUIT":
               $buffer['text'] = "*QUITS: ". $buffer['username']." ( ".$buffer['user_host']." )";
            $buffer['command'] = "QUIT";
            $buffer['channel'] = $CONFIG['channel'];

                        $user = $buffer['username'];
                        $time = date("U");
                        $query = "select * from seenlog where nick = \"$user\"";

                        $result = mysql_query($query);
                        $num = mysql_numrows($result);
                        if ($num == 1) {
                                $query = "update seenlog set last = \"$time\", online = \"0\" where nick = \"$user\"";
                                $result = mysql_query($query);

                        }
               break;
        case "NOTICE":
               $buffer['text'] = "*NOTICE: ". $buffer['username'];
            $buffer['command'] = "NOTICE";
            $buffer['channel'] = substr($buffer[2], 1);
               break;
        case "PART":
              $buffer['text'] = "*PARTS: ". $buffer['username']." ( ".$buffer['user_host']." )";
            $buffer['command'] = "PART";
            $buffer['channel'] = $CONFIG['channel'];

                        $user = $buffer['username'];
                        $time = date("U");
                        $query = "select * from seenlog where nick = \"$user\"";

                        $result = mysql_query($query);
                        $num = mysql_numrows($result);
                        if ($num == 1) {
                                $query = "update seenlog set last = \"$time\", online = \"0\" where nick = \"$user\"";
                                $result = mysql_query($query);

                        }

              break;
        case "MODE":
            $buffer['text'] = $buffer['username']." sets mode: ".$buffer[3];
            $buffer['command'] = "MODE";
            $buffer['channel'] = $buffer[2];
        break;
	case "KICK":
	    $cmd = $con['buffer']['all'];
	    $cmd = explode(" ", $cmd);	
	    $target = $cmd[3];
	    if ($target == $CONFIG['nick']) {
		cmd_send("JOIN ". $CONFIG['channel']);
	    }
	    break;
        case "NICK":
            $buffer['text'] = "*NICK: ".$buffer['username']." => ".substr($buffer[2], 1)." ( ".$buffer['user_host']." )";
            $buffer['command'] = "NICK";
            $buffer['channel'] = $CONFIG['channel'];
        break;
        
        default:
            // it is probably a PRIVMSG
            $buffer['command'] = $buffer[1];
            $buffer['channel'] = $buffer[2];
            $buffer['text'] = substr($buffer[3], 1);    
        break;    
    }
    $con['buffer'] = $buffer;
}

function prep_text($type, $message)
{
    global $con;
    return ('PRIVMSG '. $con['buffer']['channel'] .' :['.$type.']'.$message);
}

function prep_ptext($type, $message)
{
    global $con;
    return ('PRIVMSG '. $con['buffer']['username'] .' :['.$type.']'.$message);
}

function msg_text($message)
{
        global $con;
    return ('PRIVMSG '. $con['buffer']['username']. " :$message");
}

?>

