<?php
class WS {
    var $master;
    var $sockets = array();
    var $accept =array();
    var $debug = true;
    var $handshake = array();

    function __construct($address, $port){
        $this->master=socket_create(AF_INET, SOCK_STREAM, SOL_TCP)     or die("socket_create() failed");
        socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1)  or die("socket_option() failed");
        socket_bind($this->master, $address, $port)                    or die("socket_bind() failed");
        socket_listen($this->master,20)                                or die("socket_listen() failed");


        echo "Server Started : ".date('Y-m-d H:i:s');
        echo "Listening on   : ".$address." port ".$port;
        echo "Master socket  : ".$this->master."\n";

        while(true){
            $this->sockets=$this->accept;
            $this->sockets[] = $this->master;
            $write = NULL;
            $except = NULL;
            echo "while-1\n";
            print_r($this->sockets);
            socket_select($this->sockets, $write, $except, NULL);  //自动选择来消息的socket 如果是握手 自动选择主机

            foreach ($this->sockets as $key=>$socket){
                if ($socket == $this->master){  //主机
                    $client = socket_accept($this->master);
                    if ($client < 0){
                        echo "socket_accept() failed";
                        continue;
                    } else{
                        $this->connect($client);

                    }
                } else {
                    $bytes = @socket_recv($socket,$buffer,2048,0);
                    if ($bytes == 0){
                        $this->disConnect($socket);
                    }
                    else{
                        if (!$this->handshake[$key]){
                            $this->doHandShake($socket, $buffer,$key);
                            $str=json_encode(array(
                                'type'=>'num_order',
                                'num'=>$key
                            ));
                            $str=$this->frame($str);
                            socket_write($socket, $str, strlen($str));
                        }
                        else{
                            $buffer = $this->decode($buffer);
                            $str=json_encode(array(
                                'type'=>'message',
                                'num'=>$key,
                                'msg'=>$buffer
                            ));
                            $this->send($socket, $str);
                        }
                    }
                }
            }
        }
    }

    function send($client, $msg){

        $msg = $this->frame($msg);
        foreach ($this->accept as $key => $value) {
            socket_write($value, $msg, strlen($msg));
        }
        echo "send_length: " . strlen($msg);
    }
    function connect($socket){
        $this->accept[]=$socket;
        $key=array_keys($this->accept);
        $key=end($key);

        $this->handshake[$key]=0;
        echo "\n" . $socket . " CONNECTED!\n";
        echo date("Y-n-d H:i:s")."\n";
    }
    function disConnect($socket){
        $index = array_search($socket, $this->sockets);
        socket_close($socket);
        echo $socket . " DISCONNECTED!\n";
        if ($index >= 0){
            array_splice($this->sockets, $index, 1);
            array_splice($this->handshake, $index, 1);
        }
    }
    function doHandShake($socket, $buffer, $skey){
        echo "\nRequesting handshake...";
        echo $buffer."\n";
        list($resource, $host, $origin, $key) = $this->getHeaders($buffer);
        echo "Handshaking...\n";
        $upgrade  = "HTTP/1.1 101 Switching Protocol\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: " . $this->calcKey($key) . "\r\n\r\n";  //必须以两个回车结尾
        echo $upgrade."\n";
        $sent = socket_write($socket, $upgrade, strlen($upgrade));
        $this->handshake[$skey]=1;
        echo "Done handshaking...\n";
        return true;
    }

    function getHeaders($req){
        $r = $h = $o = $key = null;
        if (preg_match("/GET (.*) HTTP/"              ,$req,$match)) { $r = $match[1]; }
        if (preg_match("/Host: (.*)\r\n/"             ,$req,$match)) { $h = $match[1]; }
        if (preg_match("/Origin: (.*)\r\n/"           ,$req,$match)) { $o = $match[1]; }
        if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/",$req,$match)) { $key = $match[1]; }
        return array($r, $h, $o, $key);
    }

    function calcKey($key){
        //基于websocket version 13
        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        return $accept;
    }

    function decode($buffer) {
        $len = $masks = $data = $decoded = null;
        $len = ord($buffer[1]) & 127;

        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        }
        else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        }
        else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return $decoded;
    }

    function frame($s){
        $a = str_split($s, 125);
        if (count($a) == 1){
            return "\x81" . chr(strlen($a[0])) . $a[0];
        }
        $ns = "";
        foreach ($a as $o){
            $ns .= "\x81" . chr(strlen($o)) . $o;
        }
        return $ns;
    }

}

//IP为本地服务器IP，端口为非服务器默认端口的其他未被占用端口，比如服务器端口为80，
//则此处端口可填写8080，前端连接的端口也必须跟此处相同
new WS('127.0.0.1', 8080);