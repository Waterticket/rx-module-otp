<?php
class googleotpController extends googleotp
{
	function init()
	{
	}

	function procGoogleotpUserConfig()
	{
		if(!Context::get("is_logged")) return $this->createObject(-1, "로그인해주세요");

		$oGoogleOTPModel = getModel('googleotp');
		$member_srl = Context::get('member_srl');

		if ($this->user->is_admin !== 'Y') {
			$member_srl = $this->user->member_srl;
		}

		if(!$oGoogleOTPModel->checkUserConfig($member_srl)) {
			$oGoogleOTPModel->insertNewConfig($member_srl);
		}

		$cond = new stdClass();
		$cond->srl = $member_srl;
		$cond->use = Context::get("use") === "Y" ? "Y" : "N";
		$cond->issue_type = Context::get("issue_type") ?: 'none';

		if($cond->use == 'Y' && !in_array($cond->issue_type, array('otp', 'email', 'sms'))) {
			return $this->createObject(-1, "2차 인증 방식을 선택해주세요.");
		}

		if ($cond->use == 'Y' && $cond->issue_type == 'otp') {
			if (!Context::get('code')) {
				return $this->createObject(-1, "OTP 인증번호를 입력해주세요.");
			}
			
			$user_config = $oGoogleOTPModel->getUserConfig($member_srl);
			$ga = new PHPGangsta_GoogleAuthenticator();
			if (!$ga->verifyCode($user_config->otp_id, Context::get('code'), 2))
			{
				return $this->createObject(-1, "잘못된 OTP 인증번호입니다.");
			}
		}

		$output = executeQuery('googleotp.updateGoogleotpuserconfigbySrl', $cond);
		if(!$output->toBool()) return $this->createObject(-1, "ERROR #1 : 관리자에게 문의하세요.");

		if($cond->use === "Y")
		{
			$_SESSION['googleotp_passed'] = TRUE;
		}

		// alert a message
		if(Context::get('xeVirtualRequestMethod') !== 'xml')
		{
			$this->setMessage('success_updated');
			$this->setRedirectUrl(Context::get('error_return_url') ?: getNotEncodedUrl('', 'act', 'dispGoogleotpUserConfig'));
		}
	}

	function procGoogleotpInputotp()
	{
		if(!Context::get("is_logged")) return $this->createObject(-1,"로그인하지 않았습니다.");
		if($_SESSION['googleotp_passed']) return $this->createObject(-1,"이미 인증했습니다.");

		$config = $this->getConfig();
		if ( $config->use_captcha === 'Y' )
		{
			$spamfilter_config = ModuleModel::getModuleConfig('spamfilter');
			$logged_info = Context::get('logged_info');
			if (
				$config->use_captcha == 'Y'
				&& isset($spamfilter_config) && isset($spamfilter_config->captcha)
				&& $spamfilter_config->captcha->type === 'recaptcha'
				&& $logged_info->is_admin !== 'Y'
			)
			{
				include_once RX_BASEDIR . 'modules/spamfilter/spamfilter.lib.php';
				spamfilter_reCAPTCHA::init($spamfilter_config->captcha);
				spamfilter_reCAPTCHA::check();
			}
		}
		
		$otpnumber = Context::get("otpinput");
		
		// change 111 111 to 111111
	    $otpnumber = explode(" ",$otpnumber);
	    $otpnumber = implode("",$otpnumber);

		if(!$otpnumber) return $this->createObject(-1,"인증번호를 입력해주세요.");
	    
		$member_srl = Context::get('logged_info')->member_srl;

		$oGoogleOTPModel = getModel('googleotp');
		$config = $oGoogleOTPModel->getUserConfig($member_srl);
		$issue_type = $config->issue_type;
		
		if($oGoogleOTPModel->checkOTPNumber($member_srl,$otpnumber))
		{
		    if(!$oGoogleOTPModel->checkUsedNumber($member_srl,$otpnumber))
		    {
		        return $this->createObject(-1,"이미 인증에 사용된 번호입니다. 다른 번호를 사용해주세요.");
		    }
		    else
		    {
		        $oGoogleOTPModel->insertAuthlog($member_srl, $otpnumber, "Y", $issue_type);
			    $_SESSION['googleotp_passed'] = TRUE;
			    $this->setRedirectUrl($_SESSION['beforeaddress']);
		    }
		}
		else
		{
		    $oGoogleOTPModel->insertAuthlog($member_srl, $otpnumber, "N", $issue_type);
			$this->setError(-1);
			$this->setMessage("잘못된 인증 번호입니다");
			$this->setRedirectUrl(Context::get('error_return_url') ?: getNotEncodedUrl('', 'act', 'dispGoogleotpInputotp'));
		}
	}

	function procGoogleotpResendauthmessage()
	{
		Context::setResponseMethod('JSON');
		if($_SESSION['googleotp_passed']) return $this->createObject(-1,"이미 인증했습니다.");

		$member_srl = Context::get('member_srl');
		$oGoogleOTPModel = getModel('googleotp');
		$userconfig = $oGoogleOTPModel->getUserConfig($member_srl);

		if($userconfig->issue_type == 'email')
		{
			if($oGoogleOTPModel->AvailableToSendEmail($member_srl)) // 인증 메일을 보낼 수 있을 경우
			{
				$result = $oGoogleOTPModel->sendAuthEmail($member_srl, rand(100000, 999999));
				return $this->createObject(0, "인증 메일을 재발송했습니다.");
			}
			else
			{
				return $this->createObject(-1, "인증 메일을 재발송할 수 없습니다.\n\n관리자에게 문의하세요.");
			}
		}
		else if($userconfig->issue_type == 'sms')
		{
			if($oGoogleOTPModel->AvailableToSendSMS($member_srl)) // 인증 SMS를 보낼 수 있을 경우
			{
				$result = $oGoogleOTPModel->sendAuthSMS($member_srl, rand(100000, 999999));
				return $this->createObject(0, "인증 문자를 재발송했습니다.");
			}
			else
			{
				return $this->createObject(-1, "인증 문자를 재발송할 수 없습니다.\n\n관리자에게 문의하세요.");
			}
		}
	}

	function triggerAddMemberMenu()
	{
		$logged_info = Context::get('logged_info');
		if(!Context::get('is_logged')) return $this->createObject();

		$oMemberController = getController('member');
		$oMemberController->addMemberMenu('dispGoogleotpUserConfig', "로그인 2차 인증 설정");
		if($logged_info->is_admin== 'Y')
		{
			$target_srl = Context::get('target_srl');

			$url = getUrl('','act','dispGoogleotpUserConfig','member_srl',$target_srl);
			$oMemberController->addMemberPopupMenu($url, '유저 로그인 2차 인증 관리', '');
		}
		return $this->createObject();
	}

	function triggerHijackLogin($obj) {
		if(!Context::get("is_logged") || $obj->act === "dispMemberLogout") {
			unset($_SESSION['googleotp_passed']);
			return;
		}

		$oGoogleOTPModel = getModel('googleotp');
		$userconfig = $oGoogleOTPModel->getUserConfig(Context::get('logged_info')->member_srl);
		if($userconfig->use === "Y") {
			$allowedact = array("dispGoogleotpInputotp","procGoogleotpInputotp","procMemberLogin","dispMemberLogout","procGoogleotpResendauthmessage");
			if(!in_array($obj->act, $allowedact) && !$_SESSION['googleotp_passed'])
			{
				$_SESSION['beforeaddress'] = getNotEncodedUrl();
				header("Location: " . getNotEncodedUrl('act','dispGoogleotpInputotp'));
				Context::close();
				die();
			}
		}
	}

	public function triggerAfterDoAutoLogin($obj)
	{
		$config = $this->getConfig();
		if ($config->autologin_reauth == 'N') return;

		$member_srl = $obj->member_srl;
		$oGoogleOTPModel = googleotpModel::getInstance();
		$userconfig = $oGoogleOTPModel->getUserConfig($member_srl);
		if($userconfig->use != "Y") return;

		$_SESSION['googleotp_passed'] = true;
	}
}
