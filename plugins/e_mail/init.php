<?php
class E_Mail extends Plugin {

	private $host;

	function about() {
		return array(1.0,
			"Share full article via email with typeahead",
			"jems");
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
	}

	function get_js() {
		return file_get_contents(dirname(__FILE__) . "/e_mail.js");
	}

	function hook_article_button($line) {
		return "<img src=\"plugins/e_mail/e_mail.png\"
					class='tagsPic' style=\"cursor : pointer\"
					onclick=\"e_mailArticle(".$line["id"].")\"
					alt='Zoom' title='".__('Forward by email - enhanced')."'>";
	}


  function save_email_address($email) {
    if (!empty($email)) {
      // Insert or update
      $query = "SELECT id, content FROM ttrss_plugin_storage
              WHERE name='e_mail_addresses' and owner_uid = ".$_SESSION["uid"];
      $result = db_query($query);
      if (db_num_rows($result) > 0) {
        // this user has already stored some adresses - updating
        $line = db_fetch_assoc($result);
        if (empty($line[content])) {
                $line[content]=$email;
        } else {
                if (!preg_match('/'.$email.'/i',$line[content])) {
                        $line[content] = $line[content].";$email";
                }
        }
        db_query("update ttrss_plugin_storage set content='".$line[content]."' where id=".$line[id]);
      } else {
          // no result, first email to be stored
          db_query("insert into ttrss_plugin_storage (name, content, owner_uid) values ('e_mail_addresses','".$email."','".$_SESSION["uid"]."')");
      }
    }
  }

  function get_email_addresses() {
    $query = "SELECT id, content FROM ttrss_plugin_storage
      WHERE name='e_mail_addresses' and owner_uid = ".$_SESSION["uid"];
    $result = db_query($query);
    if (db_num_rows($result) > 0) {
      return  db_fetch_assoc($result);
    } else {
      return "";
    }
  }

	function emailArticle() {

		$param = db_escape_string($_REQUEST['param']);

		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"op\" value=\"pluginhandler\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"plugin\" value=\"e_mail\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"method\" value=\"sendEmail\">";

		$result = db_query("SELECT email, full_name FROM ttrss_users WHERE
			id = " . $_SESSION["uid"]);

		$user_email = htmlspecialchars(db_fetch_result($result, 0, "email"));
		$user_name = htmlspecialchars(db_fetch_result($result, 0, "full_name"));

		if (!$user_name) $user_name = $_SESSION['name'];

		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"from_email\" value=\"$user_email\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"from_name\" value=\"$user_name\">";

		require_once "lib/MiniTemplator.class.php";

		$tpl = new MiniTemplator;
		$tpl_t = new MiniTemplator;

		$tpl->readTemplateFromFile("templates/e_mail_article_template.txt");

		$tpl->setVariable('USER_NAME', $_SESSION["name"], true);
		$tpl->setVariable('USER_EMAIL', $user_email, true);
		$tpl->setVariable('TTRSS_HOST', $_SERVER["HTTP_HOST"], true);

		$result = db_query("SELECT link, content, title, note 
			FROM ttrss_user_entries, ttrss_entries WHERE id = ref_id AND
			id IN ($param) AND owner_uid = " . $_SESSION["uid"]);

		if (db_num_rows($result) > 1) {
			$subject = __("[Forwarded]") . " " . __("Multiple articles");
		}

		while ($line = db_fetch_assoc($result)) {

			if (!$subject)
				$subject = __("[Forwarded]") . " " . htmlspecialchars($line["title"]);

			$tpl->setVariable('ARTICLE_TITLE', strip_tags($line["title"]));
			$tnote = strip_tags($line["note"]);
			if( $tnote != ''){
				$tpl->setVariable('ARTICLE_NOTE', $tnote, true);
				$tpl->addBlock('note');
			}
			$tpl->setVariable('ARTICLE_URL', strip_tags($line["link"]));
			$tpl->setVariable('ARTICLE_CONTENT', strip_tags($line["content"]));
			$tpl->addBlock('article');
		}

		$tpl->addBlock('email');

		$content = "";
		$tpl->generateOutputToString($content);

		print "<table width='100%'><tr><td>";


		print __('From:');

		print "</td><td>";

		print "<input dojoType=\"dijit.form.TextBox\" disabled=\"1\" style=\"width : 30em;\"
				value=\"$user_name <$user_email>\">";

		print "</td></tr><tr><td>";

		print __('To:');

		print "</td><td>";

		print "<input dojoType=\"dijit.form.ValidationTextBox\" required=\"true\"
				style=\"width : 30em;\"
				name=\"destination\" id=\"emailArticleDlg_destination\">";

		print "<div class=\"autocomplete\" id=\"emailArticleDlg_dst_choices\"
				style=\"z-index: 30; display : none\"></div>";

		print "</td></tr><tr><td>";

		print __('Subject:');

		print "</td><td>";

		print "<input dojoType=\"dijit.form.ValidationTextBox\" required=\"true\"
				style=\"width : 30em;\"
				name=\"subject\" value=\"$subject\" id=\"subject\">";

		print "</td></tr>";

		print "<tr><td colspan='2'><textarea dojoType=\"dijit.form.SimpleTextarea\" style='font-size : 12px; width : 100%' rows=\"20\"
			name='content'>$content</textarea>";

		print "</td></tr></table>";

		print "<div class='dlgButtons'>";
		print "<button dojoType=\"dijit.form.Button\" onclick=\"dijit.byId('emailArticleDlg').execute()\">".__('Send e-mail')."</button> ";
		print "<button dojoType=\"dijit.form.Button\" onclick=\"dijit.byId('emailArticleDlg').hide()\">".__('Cancel')."</button>";
		print "</div>";

		//return;
	}

	function sendEmail() {
		require_once 'classes/ttrssmailer.php';

		$reply = array();

		$mail = new ttrssMailer();

		$mail->From = strip_tags($_REQUEST['from_email']);
		$mail->FromName = strip_tags($_REQUEST['from_name']);
		//$mail->AddAddress($_REQUEST['destination']);
		$addresses = explode(';', $_REQUEST['destination']);
		foreach($addresses as $nextaddr)
			$mail->AddAddress($nextaddr);

		$mail->IsHTML(false);
		$mail->Subject = $_REQUEST['subject'];
		$mail->Body = $_REQUEST['content'];

		$rc = $mail->Send();

		if (!$rc) {
			$reply['error'] =  $mail->ErrorInfo;
		} else {
			foreach($addresses as $nextaddr)
				$this->save_email_address(db_escape_string($nextaddr));
			$reply['message'] = "UPDATE_COUNTERS";
		}

		print json_encode($reply);
	}

	function completeEmails() {
		print "<ul>";
   		$search=$_POST['search']; 
		$addresses=$this->get_email_addresses();
		foreach(explode(';', $addresses['content']) as $email) {
			if (preg_match('/'.$search.'/', $email)) {
				print "<li>$email</li>";
			}
		}
		print "</ul>";
	}

	function api_version() {
		return 2;
	}

}
?>
