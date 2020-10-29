<?php
/**
 * Description of plugin
 * @author Joseph Philbert <joe@philbertphotos.com>
 * @license http://opensource.org/licenses/MIT
 * @version 0.1
 */
foreach ([
	'canned',
	'format',
	'list',
	'orm',
	'misc',
	'plugin',
	'ticket',
	'signal',
	'staff'
] as $c) {
	require_once INCLUDE_DIR . "class.$c.php";
}
require_once 'config.php';

class AssignAlertPlugin extends Plugin
{
	var $config_class = 'AssignedAgentConfig';
	static private $config;
	/**
     * The name that appears in threads as: Closer Plugin.
     *
     * @var string
     */
	const PLUGIN_NAME = 'Assign Alert Plugin';

	public function bootstrap()
	{
		self::$config = self::getConfig();
		Signal::connect('model.created', array($this, 'onModelCreated'));
	}

	function onModelCreated($object)
	{
		global $ost, $thisstaff, $cfg;
			$event_id = $object->ht {'event_id'};
			$uid = $object->ht {'uid'};

		if (get_class($object) === "ThreadEvent" && $event_id == '4' && !empty($uid)) {
			$this->log('onModelCreated', json_encode($object));
			$this->log('onModelcfg', json_encode($cfg));
			$ticket_id = self::find_ticket(
				$object->ht {
					'id'
				});			

			$indept = false;
				// Fetch ticket as an Object
			$ticket = Ticket::lookup($ticket_id);
			//$created = str_replace("-", "\\", $ticket->ht{'created'});
			$created = $ticket->ht{'created'};

				//If the the ticket was just created then there is a chance its the Filter or Agent we can skip.
			if (strtotime($created) > time() - (60*1))
				return;
			
			$this->log('onModelTicket', json_encode($ticket));

			$departID = self::$config->get('alert_dept');
		
				//If department not set then skip.
			foreach ($departID as $id) {
				if ($ticket->getDeptId() == $id || $id == 0) {
					$indept = true;
					continue;
				}
			}

			$admin_reply = self::$config->get('alert-msg');
			$admin_canned = self::$config->get('alert-canned');
			$admin_subject = self::$config->get('alert-subject');

			if (is_numeric($admin_canned) && $admin_canned) {
                        // We have a valid Canned_Response ID, fetch the actual Canned:
				$admin_canned = Canned::lookup($admin_canned);
				if ($admin_canned instanceof Canned) {
                            // Got a real Canned object, let's pull the body/string:
					$admin_canned = $admin_canned->getFormattedResponse('html');
				}
			}

                    // Get the robot for this group
			$robot = self::$config->get('alert-account');
			$robot = ($robot > 0) ? $robot = Staff::lookup($robot) : null;

			switch (self::$config->get('alert-choice')) {
				case '0':
					if (self::$config->get('debug'))
						$this->log("Assigned Message", $this->updateVars($ticket, $admin_canned) . " " . $ticket->getSubject());
					break;
				case '1':
					$ticket->LogNote(__('Notification'), __($this->updateVars($ticket, $admin_canned)), self::PLUGIN_NAME, FALSE);
					break;
				case '2':
					$this->post_reply($ticket, $admin_canned, $robot);
					break;
			} 

			$this->sendMailAlert($ticket, $admin_subject, $admin_reply);
			$status = self::$config->get('alert-status');
			if (!$status == 0){
             $new_status = TicketStatus::lookup(array('id' => (int) $status));
				//change_status($ticket, $new_status)
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
     * This is the part that actually "Closes" the tickets Well, depending on the
     * admin settings I mean. Could use $ticket->setStatus($closed_status)
     * function however, this gives us control over _how_ it is closed. preventing
     * accidentally making any logged-in staff associated with the closure, which
     * is an issue with AutoCron
     *
     * @param Ticket $ticket
     * @param TicketStatus $new_status
     */
	private function change_status(Ticket $ticket, TicketStatus $new_status)
	{
		if (self::DEBUG) {
			error_log(
				"Setting status " . $new_status->getState()
					. " for ticket {$ticket->getId()}::{$ticket->getSubject()}"
			);
		}

			// Start by setting the last update and closed timestamps to now
		$ticket->closed = $ticket->lastupdate = SqlFunction::NOW();

			// Remove any duedate or overdue flags
		$ticket->duedate = null;
		$ticket->clearOverdue(FALSE); // flag prevents saving, we'll do that
			// Post an Event with the current timestamp.
		$ticket->logEvent(
			$new_status->getState(),
			[
				'status' => [
					$new_status->getId(),
					$new_status->getName()
				]
			]
		);
			// Actually apply the new "TicketStatus" to the Ticket.
		$ticket->status = $new_status;

			// Save it, flag prevents it refetching the ticket data straight away (inefficient)
		$ticket->save(FALSE);
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

		//self::logger('info', 'onModelFrom', json_encode($from_address));
		try {
			$mailer = new Mailer();
			$mailer->setFromAddress($this->FromMail()[$from_address]);
			$mailer->send($to_address, $subject, $msg);
		} catch (\Exception $e) {
			$ost->logError('Mail alert posting issue!', $e->getMessage(), true);
		}
	}

		//Get the list of osticket emails
	function FromMail()
	{
		$frommail = array();
		$sql = 'SELECT email_id,email,name,smtp_active FROM ' . EMAIL_TABLE . ' email ORDER by name';
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
			$ost->logWarning($title, $message, false);
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

	/**
     * Write information to system LOG
     *
     */
	function logger($priority, $title, $message)
	{
        // if (!empty(self::getConfig()->get('debug-choice')) &&  self::getConfig()->get('debug-choice')) {
			//$array = json_decode(json_encode($response->response->docs), true);
		if (is_array($message) || is_object($message)) {
			$msg = json_decode(json_encode($message), true);
			$message = "array:" . json_encode($msg);
			if (is_object($message)) $message = "object:" . self::toArray($message);
		} else {
			$message = $message;
		}
            // }
            // We are providing only 3 levels of logs. Windows style.
		switch ($priority) {
			case 1:
			case LOG_EMERG:
			case LOG_ALERT:
			case LOG_CRIT:
			case LOG_ERR:
				$level = 1; //Error
				break;

			case 2:
			case LOG_WARN:
			case LOG_WARNING:
				$level = 2; //Warning
				break;

			case 3:
			case LOG_NOTICE:
			case LOG_INFO:
			case LOG_DEBUG:
			default:
				$level = 3; //Debug
		}
		$loglevel = array(
			1 => 'Error',
			'Warning',
			'Debug'
		);
            // Save log based on system log level settings.
		$sql = 'INSERT INTO ' . SYSLOG_TABLE . ' SET created=NOW(), updated=NOW() ' . ',title=' . db_input(Format::sanitize($title, true)) . ',log_type=' . db_input($loglevel[$level]) . ',log=' . db_input(Format::sanitize($message, false)) . ',ip_address=' . db_input($_SERVER['REMOTE_ADDR']);
		db_query($sql, false);
	}
}
