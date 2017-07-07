<?php
abstract class SipBaseCallSession extends SipSession
{
	public $local_sdp;
	public $remote_sdp;
	
	function __construct(){
		parent::__construct();
	}
	
	function incoming($msg, $trans){
		if($msg->method == 'BYE' || $msg->method == 'CANCEL'){
			if($trans->state == SIP::FIN_WAIT){
				Logger::debug($this->role_name() . " {$this->call_id} FIN_WAIT => CLOSE_WAIT");
				$this->onclose($msg);
				return true;
			}
			if($trans->state == SIP::CLOSE_WAIT){
				Logger::debug("recv " . $msg->method . " while CLOSE_WAIT");
				$trans->nowait();
				return true;
			}
			
			Logger::debug($this->role_name() . " {$this->call_id} close by " . $msg->method);
			$this->onclose($msg);
			return true;
		}
		
		if($trans->state == SIP::FIN_WAIT){
			if($msg->code == 200){
				Logger::info("recv {$msg->code} {$msg->reason}, finish CLOSE_WAIT " . $this->role_name());
				$this->terminate();
				return true;
			}
		}
		
		///////////////////////////////////////////
		
		if($trans->state == SIP::KEEPALIVE){
			// TODO: 415 不一定正确
			if($msg->code == 200 || $msg->code == 415){ // 415 Unsupported Media Type
				$trans->keepalive();
				return true;
			}
			// 有些 pbx 不能很好地处理 INFO，降级使用 OPTIONS
			if($msg->code == 500 && in_array('INFO', $this->remote_allow)){
				Logger::debug("INFO response 500, use OPTIONS instead");
				foreach($this->remote_allow as $index=>$cmd){
					if($cmd === 'INFO'){
						unset($this->remote_allow[$index]);
						break;
					}
				}
				return true;
			}
		}
		
		if($msg->code == 180 && ($this->is_state(SIP::CALLING) || $this->is_state(SIP::TRYING))){
			$this->set_state(SIP::RINGING);
			return true;
		}
		if($msg->code >= 300 && $msg->code < 400){
			// ...
			Logger::info("nothing to do with {$msg->code}");
		}else if($msg->code == 404 || $msg->code == 481){
			Logger::info("recv {$msg->code} {$msg->reason}, terminate " . $this->role_name());
			$this->terminate();
			return true;
		}
	}
	
	function outgoing($trans){
		if($trans->state == SIP::KEEPALIVE){
			Logger::debug("refresh " . $this->role_name() . " session {$this->call_id}");

			// 某些 PBX 没有检测客户端异常，所以 keepalive 未必有效。
			$msg = new SipMessage();
			if(in_array('INFO', $this->remote_allow)){
				$msg->method = 'INFO';
				$msg->add_header('Content-Type', 'application/sdp');
			}else{
				$msg->method = 'OPTIONS';
			}
			return $msg;
		}else if($trans->state == SIP::FIN_WAIT){
			$msg = new SipMessage();
			$msg->method = $trans->method;
			// 对方收到 CANCEL 后，会先回复 487 Request Terminated 给之前的请求，
			// 然后回复 OK 给 CANCEL，目前的实现会 drop 487
			return $msg;
		}else if($trans->state == SIP::CLOSE_WAIT){
			$msg = new SipMessage();
			$msg->code = 200;
			$msg->cseq_method = $trans->method;
			return $msg;
		}
	}
	
}
