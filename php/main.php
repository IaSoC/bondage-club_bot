<?php
use Workerman\Worker;
use Workerman\Timer;
use Workerman\Connection\AsyncTcpConnection;
require_once __DIR__ . '/vendor/autoload.php';

$worker = new Worker();

$GLOBALS['CustomValue'] = array(
    'Owner' => 81587
);
$Player = array();
$WatchDog = array();
$Chatroom = array();


$worker->onWorkerStart = function($worker){

    $con = new AsyncTcpConnection('ws://127.0.0.1:13254');

    $con->onConnect = function(AsyncTcpConnection $con) {
        global $WatchDog,$Player;
        $WatchDog['ServerInfo_KeepAlive'] = time();
        emit($con,"AccountLogin",array("AccountName"=>getenv('username'),"Password"=>getenv('passwd')));
        Timer::add(30, function(){
            global $WatchDog,$con;
            if(($WatchDog['ServerInfo_KeepAlive'] + 60) < time()){
                echo '服务器无响应,尝试重连.'.PHP_EOL;
                $con->close();
            }
        });
    };

    $con->onMessage = function(AsyncTcpConnection $con, $data) {
        global $WatchDog,$Player,$Chatroom;
        $data0 = json_decode($data,true);

        echo $data.PHP_EOL;

        $event = $data0[0];
        $data = $data0[1];
        unset($data0);

        file_put_contents('main.json',json_encode(array($event,$data),JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);

        switch ($event) {
            case 'ServerInfo':
                echo '当前服务器在线人数: '.$data['OnlinePlayers'].PHP_EOL;
                $WatchDog['ServerInfo_KeepAlive'] = time();
                break;
            case 'ForceDisconnect':
                $con->close();
                break;
            case 'LoginQueue':
                echo '正在排队,剩余长度: '.$data.PHP_EOL;
            case 'LoginResponse':
                $Player = $data;
                echo $Player['Environment'].PHP_EOL;

                emit($con,'AccountUpdate',array(
                    'Inventory' => $Player['Inventory'],
                    'OnlineSettings' => $Player['OnlineSettings']
                ));

                emit($con,'AccountUpdate',array('Game' => $Player['Game']));

                //emit($con,'ChatRoomSearch',array('Query'=>'','Language'=>'','Space'=>'','Game'=>'','FullRooms'=>false,'Ignore'=>array()));
                break;
            
            case 'ChatRoomSearchResponse':
                switch ($data) {
                    case 'JoinedRoom':
                        $GLOBALS['CustomValue']['InChatRoom'] = true;
                        /*
                        emit($con,'ChatRoomChat',array(
                            'Content' => '您好,我是'.$Player['Name'].'.',
                            'Type' => 'Chat'
                        ));
                        */
                        break;
                    case 'RoomKicked':
                        emit($con,'AccountBeep',array(
                            "MemberNumber" => $GLOBALS['CustomValue']['Owner'],
                            "BeepType" => "",
                            "Message" => '已被房间: '.$Player['LastChatRoom'].' 踢出'
                        ));
                        break;
                    case 'RoomFull':
                        emit($con,'AccountBeep',array(
                            "MemberNumber" => $GLOBALS['CustomValue']['Owner'],
                            "BeepType" => "",
                            "Message" => '房间已满'
                        ));
                        break;
                    default:
                        # code...
                        break;
                }
                break;
            
            case 'AccountBeep':
                if($data['MemberNumber'] == $GLOBALS['CustomValue']['Owner']){
                    $voice_command = json_decode($data['Message'],true);
                    switch ($voice_command['action']) {
                        case 'sendmsg':
                            emit($con,'ChatRoomChat',array(
                                'Content' => $voice_command['msg'],
                                'Type' => 'Chat'
                            ));
                            break;
                        case 'quit':
                            emit($con,'ChatRoomLeave','');
                            break;
                        case 'come':
                            emit($con,'ChatRoomJoin',array('Name'=>$data['ChatRoomName']));
                            break;
                        default:
                            emit($con,'AccountBeep',array(
                                "MemberNumber" => $data['MemberNumber'],
                                "BeepType" => "",
                                "Message" => '标准格式为:'.PHP_EOL.'{"action":"事件","msg":"附加信息"}'
                            ));
                            break;
                    }
                }
                break;

            case 'ChatRoomSync':
                $Chatroom['Character'] = $data['Character'];
                emit($con,'AccountUpdate',array(
                    'LastChatRoom' => $data['Name'],
                    'LastChatRoomBG' => $data['Background'],
                    'LastChatRoomPrivate' => $data['Private'],
                    'LastChatRoomSize' => $data['Limit'],
                    'LastChatRoomLanguage' => $data['Language'],
                    'LastChatRoomDesc' => $data['Description'],
                    'LastChatRoomAdmin' => $data['Admin'],
                    'LastChatRoomBan' => $data['Ban'],
                    'LastChatRoomBlockCategory' => $data['BlockCategory']
                ));
                break;
            case 'ChatRoomMessage':
                break;
            default:
                # code...
                break;
        }

    };

    $con->onClose = function(AsyncTcpConnection $con) {
        $con->connect();
    };

    $con->connect();
};

Worker::runAll();

function emit($con,$event,$data){
    $data_sent = json_encode(array($event,$data),JSON_UNESCAPED_UNICODE);
    echo $data_sent.PHP_EOL;
    $con->send($data_sent);
}