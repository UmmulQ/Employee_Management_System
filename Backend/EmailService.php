<?php
require_once 'connect.php';

class EmailService {
    private $smtpHost;
    private $smtpPort;
    private $smtpUsername;
    private $smtpPassword;

    public function __construct() {
        $this->smtpHost = SMTP_HOST;
        $this->smtpPort = SMTP_PORT;
        $this->smtpUsername = SMTP_USERNAME;
        $this->smtpPassword = SMTP_PASSWORD;
    }

    public function sendMeetingInvitation($toEmail, $toName, $meetingData) {
        $subject = "Meeting Invitation: " . $meetingData['title'];
        
        $message = $this->getEmailTemplate($toName, $meetingData);
        
        return $this->sendEmail($toEmail, $subject, $message);
    }

    private function getEmailTemplate($name, $meetingData) {
        $formattedDate = date('F j, Y', strtotime($meetingData['meeting_date']));
        $startTime = date('g:i A', strtotime($meetingData['start_time']));
        $endTime = date('g:i A', strtotime($meetingData['end_time']));

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #1d7575; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #f9f9f9; }
                .meeting-details { background: white; padding: 15px; border-left: 4px solid #1d7575; margin: 15px 0; }
                .button { display: inline-block; padding: 12px 24px; background: #1d7575; color: white; text-decoration: none; border-radius: 5px; }
                .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Meeting Invitation</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$name}</strong>,</p>
                    <p>You have been invited to a meeting. Here are the details:</p>
                    
                    <div class='meeting-details'>
                        <h3>{$meetingData['title']}</h3>
                        <p><strong>Subject:</strong> {$meetingData['subject']}</p>
                        <p><strong>Date:</strong> {$formattedDate}</p>
                        <p><strong>Time:</strong> {$startTime} - {$endTime}</p>
                        <p><strong>Duration:</strong> {$meetingData['duration']} minutes</p>
                        <p><strong>Priority:</strong> " . ucfirst($meetingData['priority']) . "</p>
                        <p><strong>Description:</strong><br>{$meetingData['description']}</p>
                    </div>

                    <p>Click the button below to join the meeting:</p>
                    <p>
                        <a href='{$meetingData['meet_link']}' class='button' style='color: white;'>
                            Join Meeting Now
                        </a>
                    </p>
                    
                    <p>Or copy this link: <br><code>{$meetingData['meet_link']}</code></p>
                    
                    <p>Best regards,<br>Online Meetings System</p>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    private function sendEmail($to, $subject, $message) {
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . FROM_NAME . " <" . FROM_EMAIL . ">" . "\r\n";
        $headers .= "Reply-To: " . FROM_EMAIL . "\r\n";

        // For production, use PHPMailer or similar library
        // This is a basic implementation using mail()
        return mail($to, $subject, $message, $headers);
    }
}
?>