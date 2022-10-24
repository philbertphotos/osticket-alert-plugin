<?php
/**
 * Description of plugin
 * @author Joseph Philbert <joe@philbertphotos.com>
 * @license http://opensource.org/licenses/MIT
 * @version 1.4
 */
 // load various classes
foreach ([
	'plugin','canned','email','ticket','staff'
] as $c) {
	require_once INCLUDE_DIR . "class.$c.php";

require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once(INCLUDE_DIR . 'class.format.php');

}
require_once 'config.php';

class AssignAlertPlugin extends Plugin
{
	var $config_class = 'AssignedAgentConfig';
	/**
     * The name that appears in threads as: Closer Plugin.
     *
     * @var string
     */
	const PLUGIN_NAME = 'Assign Alert Plugin';

	public function bootstrap()
	{
		$config = $this->getConfig();
		Signal::connect('model.created', array($this, 'assignModeCheck'));
		
	}

	function assignModeCheck($object)
	{
		global $ost, $cfg;
			$event_id = $object->ht['event_id'];
			$uid = $object->ht['uid'];

		if (get_class($object) === "ThreadEvent" && $event_id == '4' && !empty($uid)) {
			$ticket_id = self::find_ticket(
				$object->ht['id']);			

				// Fetch ticket as an Object
			$ticket = Ticket::lookup($ticket_id);
			$created = $ticket->ht['created'];

				//If the the ticket was just created then there is a chance its the Filter or Agent we can skip.
			if (strtotime($created) > time() - (60*1))
			//$ost->logWarning('assign-info', json_encode($config), false);
			error_log("test: " . json_encode($config));
			$departID = $this->getConfig()->get('alert_dept');
		
			//If department not set then skip.
			//if (|| ($id == 0  || $id = null)){
			//} else {
			foreach ($departID as $id) {
				if ($ticket->getDeptId() == $id) {
					continue;
				}
			}
			//}

			$admin_reply = $this->getConfig()->get('alert-msg');
			$admin_canned = $this->getConfig()->get('alert-canned');
			$admin_subject = $this->getConfig()->get('alert-subject');

			if (is_numeric($admin_canned) && $admin_canned) {
                    // We have a valid Canned_Response ID, fetch the actual Canned:
				$admin_canned = Canned::lookup($admin_canned);
				if ($admin_canned instanceof Canned) {
                    // Got a real Canned object, let's pull the body/string:
					$admin_canned = $admin_canned->getFormattedResponse('html');
				}
			}

                // Get the robot for this group
			$robot = $this->getConfig()->get('alert-account');
			$robot = ($robot > 0) ? $robot = Staff::lookup($robot) : null;

			switch ($this->getConfig()->get('alert-choice')) {
				case '0':
						$this->log("Assigned Message", $this->updateVars($ticket, $admin_canned) . "<div> subject: " . $ticket->getSubject() . "</div><div> id: " . $ticket->getID() . "</div>");
					break;
				case '1':
					$ticket->LogNote(__('Notification'), __($this->updateVars($ticket, $admin_canned)), self::PLUGIN_NAME, FALSE);
					break;
				case '2':
					$this->post_reply($ticket, $admin_canned, $robot);
					break;
			} 

			$this->sendMailAlert($ticket, $admin_subject, $admin_reply);
			$status = $this->getConfig()->get('alert-status');
			if (!$status == 0){
             $new_status = TicketStatus::lookup(array('id' => (int) $status));
			}

		}
	}

	function find_ticket($id)
	{
		$sql = "SELECT object_id FROM " . TABLE_PREFIX . "thread  WHERE `id` =  (SELECT thread_id FROM " . TABLE_PREFIX . "thread_event  WHERE `id` =  " . $id . ")";
		$result = db_query($sql);
		$ids;
		while ($i = db_fetch_array($result, MYSQLI_ASSOC)) {
			$ids = $i['object_id'];
		}
		return $ids;
	}

	/**
     * Sends a reply to the ticket creator Wrapper/customizer around the
     * Ticket::postReply method.
     *
     * @param Ticket $ticket
     * @param TicketStatus $new_status
     * @param string $admin_reply
     */
	function post_reply(Ticket $ticket, $admin_reply, Staff $robot = null)
	{
			// We need to override this for the notifications
		global $thisstaff;

		if ($robot) {
			$assignee = $robot;
		} else {
			$assignee = $ticket->getAssignee();
			if (!$assignee instanceof Staff) {
                // Nobody, or a Team was assigned, and we haven't been told to use a Robot account.
				$ticket->logNote(
					__('AutoAssign Error'),
					__(
						'Unable to send reply, no assigned Agent on ticket, and no Robot account specified in config.'
					),
					self::PLUGIN_NAME,
					FALSE
				);
				return;
			}
		}
			// This actually bypasses any authentication/validation checks..
		$thisstaff = $assignee;

        // Use the Ticket objects own replaceVars method, which replace
        // any other Ticket variables.
		$custom_reply = $this->updateVars($ticket, $admin_reply);

			// Build an array of values to send to the ticket's postReply function
			// don't send notification to all collaborators.
		$vars = [
			'response' => $custom_reply
		];
		$errors = array();

		if (!$sent = $ticket->postReply($vars, $errors, 5, FALSE)) {
			$ticket->LogNote(__('Error Notification'), __('We were unable to post a reply to the ticket creator.'), self::PLUGIN_NAME, FALSE);
		}
}

	function sendMailAlert(Ticket $ticket, $subject, $body)
	{
		global $ost, $cfg;
		$email = $ticket->getEmail()->{'email'};
		$name = $ticket->getName()->{'name'};

		$from_address = $this->getConfig()->get('agent_from');
		$to_address = $name . "<" . $email . ">";

		$msg = $this->updateVars($ticket, $body);
		$subject = $this->updateVars($ticket, $subject);
		
		if ($this->getConfig()->get('debug')){
			$ost->logWarning('assignalert - subject', $subject, false);
		}
		
		try {
			$email=Email::lookup($from_address);
			$email->send($to_address, $subject, $msg);
		} catch (\Exception $e) {
			$ost->logError('Mail alert posting issue!', $e->getMessage(), true);
		}
	}

		//Get the list of osticket emails
	function FromMail()
	{
		$frommail = array();
		$sql = 'SELECT email_id,email,name FROM ' . EMAIL_TABLE . ' email ORDER by name';
		if (($res = db_query($sql)) && db_num_rows($res)) {
			while (list($id, $email, $name, $smtp) = db_fetch_row($res)) {
                //$selected=($info['email_id'] && $id==$info['email_id'])?'selected="selected"':'';
				if ($name) $email = Format::htmlchars("$name <".$email.">");
				if ($smtp) $email .= ' (' . __('SMTP') . ')';
				$frommail[$id] = $email;
			}
			return $frommail;
		}
	}
	
	function toArray($obj)
	{
		if (is_object($obj)) $obj = (array)$obj;
		if (is_array($obj)) {
			$new = array();
			foreach ($obj as $key => $val) {
				$new[$key] = self::toArray($val);
			}
		} else {
			$new = $obj;
		}

		return $new;
	}

	/**
   * Logging function, Ensures we have permission to log before doing so
   * Attempts to log to the Admin logs, and to the webserver logs if debugging
   * is enabled.
   *
   * @param string $title, string $message
   */
	private function log($title, $message)
	{
		global $ost;
		if ($this->getConfig()->get('debug') && $message) {
			$ost->logInfo($title, $message, false);
		}
	}

	/**
   * Replace variables in text
   *
   * @param Ticket $ticket, string $text
   */
	private function updateVars(Ticket $ticket, $text)
	{
        // Replace any ticket variables in the message:
		$variables = [
			'recipient' => $ticket->getOwner()

		];
		return $ticket->replaceVars($text, $variables);
	}
}
