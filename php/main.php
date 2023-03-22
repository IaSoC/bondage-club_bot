<?php
use Workerman\Worker;
use Workerman\Timer;
use Workerman\Connection\AsyncTcpConnection;
require_once __DIR__ . '/vendor/autoload.php';


$worker = new Worker();

$GLOBALS['SystemVaule']['TryToCreateRoom'] = false;
$GLOBALS['CustomValue'] = array(
    'Owner' => [81587],
    'BlackList' => array(
        //78843,//Skill鳶
        //79820//pleasant鴛
    )
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
        //emit($con,"AccountLogin",json_decode(file_get_contents('account.json'),true));
        
        //添加Watchdog计时器
        Timer::add(30, function(){
            global $WatchDog,$con;
            if(($WatchDog['ServerInfo_KeepAlive'] + 60) < time()){
                logMessage('服务器无响应,尝试重连.');
                $con->close();
            }
        });
    };

    $con->onMessage = function(AsyncTcpConnection $con, $data) {
        global $WatchDog,$Player,$Chatroom;
        $data0 = json_decode($data,true);

        //顯示日志
        //啓用, 更改爲指定函數
        //echo $data.PHP_EOL;

        $event = $data0[0];
        $data = $data0[1];
        unset($data0);

        //保存日志
        //file_put_contents('main.json',json_encode(array($event,$data),JSON_UNESCAPED_UNICODE).PHP_EOL,FILE_APPEND);

        switch ($event) {
            case 'ServerInfo':
                logMessage ('当前服务器在线人数: '.$data['OnlinePlayers']);
                $GLOBALS['SystemVaule']['OnlinePlayers'] = $data['OnlinePlayers'];
                //刷新WatchDog計時器
                $WatchDog['ServerInfo_KeepAlive'] = time();
                break;
            case 'ForceDisconnect':
                $con->close();
                break;
            case 'LoginQueue':
                logMessage('正在排队,剩余长度: '.$data);
            case 'LoginResponse':
                $Player = $data;
                //確定爲PRDO環境
                logMessage('環境: '.$Player['Environment']);

                emit($con,'AccountUpdate',array(
                    'Inventory' => $Player['Inventory'],
                    'OnlineSettings' => $Player['OnlineSettings']
                ));

                emit($con,'AccountUpdate',array('Game' => $Player['Game']));

                //emit($con,'ChatRoomSearch',array('Query'=>'','Language'=>'','Space'=>'','Game'=>'','FullRooms'=>false,'Ignore'=>array()));
                //保存Appearence
                $GLOBALS['SystemVaule']['Appearance'] = $Player['Appearance'];

                //設置姿势
                //["ChatRoomCharacterPoseUpdate", {Pose: ["LegsClosed"]}]
                emit($con,'ChatRoomCharacterPoseUpdate',array('Pose'=>'LegsClosed'));

                //拉取用户的顯示名稱, 如果有Nicname就是Nickname 沒有就是Name
                $GLOBALS['SystemVaule']['self_display_name'] = $Player['Name'];
                if(isset($Player['Nickname']) and !is_null($Player['Nickname'])){$GLOBALS['SystemVaule']['self_display_name'] = $Player['Nickname'];} 

                //如果有最後的房間存在,就嘗試重新加入
                if(!empty($Player['LastChatRoom'])){
                    ChatroomJoin($Player['LastChatRoom'],$con);
                }

                if($GLOBALS['SystemVaule']['TryToCreateRoom']){
                    //創建房間
                    emit($con,'ChatRoomCreate',array(
                        'Name' => $voice_command['name'],
                        'Background' => 'SpaceCaptainBedroom',
                        'Private' => true,
                        'Locked' => false,
                        'Game' => '',
                        'Limit' => 10,
                        'Language' => 'CN',
                        'Description' => 'Chika创建的房间, 等待占领!',
                        'Admin' => array($Player['MemberNumber'],$data['MemberNumber']),
                        'Ban' => array(),
                        'BlockCategory' => array()
                    ));
                }

                break;
            
            case 'ChatRoomSearchResponse':
                switch ($data) {
                    case 'JoinedRoom':
                        logMessage('加入房間成功');
                        /*
                        emit($con,'ChatRoomChat',array(
                            'Content' => '您好,我是'.$Player['Nickname'].'.',
                            'Type' => 'Chat'
                        ));
                        */
                        $GLOBALS['SystemVaule']['TryToCreateRoom'] = false;
                        break;
                    case 'RoomKicked':
                        logMessage('被房間踢出');
                        emit($con,'AccountBeep',array(
                            "MemberNumber" => $GLOBALS['CustomValue']['Owner'][0],
                            "BeepType" => "",
                            "Message" => '已被房间: '.$Player['LastChatRoom'].' 踢出'
                        ));
                        break;
                    case 'RoomFull':
                        logMessage('嘗試加入的房間已滿');
                        emit($con,'AccountBeep',array(
                            "MemberNumber" => $GLOBALS['CustomValue']['Owner'][0],
                            "BeepType" => "",
                            "Message" => '房间已满'
                        ));
                        break;
                    case 'CannotFindRoom':
                        logMessage('無法找到房間');
                        //如果无法找到房間且确认尝试加入的房間是最後的房間
                        if($GLOBALS['SystemVaule']['TryJoinChatroom'] == $Player['LastChatRoom']){
                            //如果不在房管列表就把自己追加進去
                            if(!in_array($Player['MemberNumber'], $Player['LastChatRoomAdmin'])){
                                array_push($Player['LastChatRoomAdmin'],$GLOBALS['CustomValue']['Owner']);
                                $Player['LastChatRoomAdmin'][] = $Player['MemberNumber'];
                            }
                            logMessage('無法找到房間, 所以創建了最後加入的房間');
                            //創建房間
                            emit($con,'ChatRoomCreate',array(
                                'Name' => $Player['LastChatRoom'],
                                'Background' => $Player['LastChatRoomBG'],
                                'Private' => $Player['LastChatRoomPrivate'],
                                'Locked' => false,
                                'Game' => '',
                                'Limit' => $Player['LastChatRoomSize'],
                                'Language' => $Player['LastChatRoomLanguage'],
                                'Description' => $Player['LastChatRoomDesc'],
                                'Admin' => $Player['LastChatRoomAdmin'],
                                'Ban' => $Player['LastChatRoomBan'],
                                'BlockCategory' => $Player['LastChatRoomBlockCategory']
                            ));
                        }
                        break;
                    case 'RoomBanned':
                        logMessage('已被房間封禁');
                        emit($con,'AccountBeep',array(
                            "MemberNumber" => $GLOBALS['CustomValue']['Owner'],
                            "BeepType" => "",
                            "Message" => '已被房間封禁'
                        ));
                        break;
                    default:
                        # code...
                        break;
                }
                break;
            
            case 'AccountBeep':
                //如果是Leash的Beep就直接不管啦w 反正除了Leash和BCX好像正常也不會用到這個
                if($data['BeepType'] == 'Leash'){
                    break;
                }
                logMessage('收到Beep');
                //確認過眼神, 是咱的Master
                if(CharacterIsAdmin($data['MemberNumber'])){
                    logMessage('收到管理員Beep');
                    //单独拿出來Beep的Message處理
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
                            $Player['LastChatRoom'] = '';
                            emit($con,'AccountUpdate',array(
                                'LastChatRoom' => ''
                            ));
                            break;
                        case 'come':
                            //類似Summon
                            ChatroomJoin($data['ChatRoomName'],$con);
                            break;
                        case 'addWL':
                            //添加白名單
                            if(!CharacterIsWhitelist($voice_command['mn'])){
                                AddCharacter2Whitelist($voice_command['mn'],$con);
                            }else{
                                emit($con,'AccountBeep',array(
                                "MemberNumber" => $data['MemberNumber'],
                                "BeepType" => "",
                                "Message" => $voice_command['mn'].'已位于白名單内'
                            ));
                            }
                            break;
                        case 'relogin':
                            $con->close();
                            break;
                        case 'CPose':
                            //更改角色動作
                            emit($con,'ChatRoomCharacterPoseUpdate',array('Pose'=>$voice_command['pose']));
                            break;
                        case 'CRoom':
                                //創建房間
                                emit($con,'ChatRoomCreate',array(
                                    'Name' => $voice_command['name'],
                                    'Background' => 'SpaceCaptainBedroom',
                                    'Private' => true,
                                    'Locked' => false,
                                    'Game' => '',
                                    'Limit' => 10,
                                    'Language' => 'CN',
                                    'Description' => 'Chika创建的房间, 等待占领!',
                                    'Admin' => array($Player['MemberNumber'],$data['MemberNumber']),
                                    'Ban' => array(),
                                    'BlockCategory' => array()
                                ));
                                $GLOBALS['SystemVaule']['TryToCreateRoom'] = true;
                                $GLOBALS['SystemVaule']['TryToCreateRoom']['RName'] = $voice_command['name'];
                            break;
                        case 'debug':
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
                //进入房間, 同步數據
                $Chatroom = $data;
                logMessage('成功加入房間');
                
                //保存到账户
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

                //假的BCX和FBC~
                emit($con,'ChatRoomChat',json_decode('{"Sender": '.$Player['MemberNumber'].', "Content": "BCEMsg", "Type": "Hidden", "Dictionary": [{"message": {"type": "Hello", "version": "6.16", "alternateArousal": false, "replyRequested": false, "capabilities": ["clubslave"]}}]}',true));
                emit($con,'ChatRoomChat',json_decode('{"Sender": '.$Player['MemberNumber'].', "Content": "BCXMsg", "Type": "Hidden", "Dictionary": {"type": "hello", "message": {"version": "19.198.10-114514w", "request": false, "effects": {"Effect": []}, "typingIndicatorEnable": true, "screenIndicatorEnable": true}}}',true));

                //進入房間進行判斷
                if(IsRoomadmin($data['Admin'])){
                    //ban掉拉黑的用户
                    setRoomBlackList($con,$data['Ban']);
                }

                break;
            case 'ChatRoomSyncMemberLeave':
                logMessage($data['SourceMemberNumber'].'離開了房間');
                //有角色离开聊天室
                unset($Chatroom['Character'][MNgetArray($data['SourceMemberNumber'])]);
                $Chatroom['Character'] = array_merge($Chatroom['Character']);
                break;
            case 'ChatRoomSyncMemberJoin':
                logMessage($data['SourceMemberNumber'].'進入了房間');
                //有角色加入聊天室
                $Chatroom['Character'][] = $data['Character'];

                //拉取用户的顯示名稱, 如果有Nicname就是Nickname 沒有就是Name
                $display_name = $data['Character']['Name'];
                if(isset($data['Character']['Nickname'])) $display_name = $data['Character']['Nickname'];

                if($data['SourceMemberNumber'] == 70270){
                    //擼貓啦!
                    emit($con,'ChatRoomChat',array(
                        'Sender' => $Player['MemberNumber'],
                        'Content' => 'ChatOther-ItemNeck-Tickle',
                        'Type' => 'Activity',
                        'Dictionary' => array(
                            array(
                                'Tag' => 'SourceCharacter',
                                'Text' => $Player['Nickname'],
                                'MemberNumber' => $Player['MemberNumber']
                            ),
                            array(
                                'Tag' => 'TargetCharacter',
                                'Text' => $display_name,
                                'MemberNumber' => $data['SourceMemberNumber']
                            ),
                            array(
                                'Tag' => 'ActivityGroup',
                                'Text' => 'ItemNeck'
                            ),
                            array(
                                'Tag' => 'ActivityName',
                                'Text' => 'Tickle'
                            ),
                            array(
                                'Tag' => 'nonce',
                                'Text' => mt_rand()
                            )
                        )
                    ));
                }
                /*
                if($data['SourceMemberNumber'] == 101915 or $data['SourceMemberNumber'] == 101281){
                    //是瞳瞳
                    emit($con,'ChatRoomChat',array(
                        'Sender' => $Player['MemberNumber'],
                        'Content' => 'ChatOther-ItemMouth-Kiss',
                        'Type' => 'Activity',
                        'Dictionary' => array(
                            array(
                                'Tag' => 'SourceCharacter',
                                'Text' => $Player['Nickname'],
                                'MemberNumber' => $Player['MemberNumber']
                            ),
                            array(
                                'Tag' => 'TargetCharacter',
                                'Text' => $display_name,
                                'MemberNumber' => $data['SourceMemberNumber']
                            ),
                            array(
                                'Tag' => 'ActivityGroup',
                                'Text' => 'ItemMouth'
                            ),
                            array(
                                'Tag' => 'ActivityName',
                                'Text' => 'Kiss'
                            ),
                            array(
                                'Tag' => 'nonce',
                                'Text' => mt_rand()
                            )
                        )
                    ));
                }
                */

                //送她一個抱抱!❤
                emit($con,'ChatRoomChat',array(
                                'Sender' => $Player['MemberNumber'],
                                'Content' => 'ChatOther-ItemHead-Pet',
                                'Type' => 'Activity',
                                'Dictionary' => array(
                                    array(
                                        'Tag' => 'SourceCharacter',
                                        'Text' => $GLOBALS['SystemVaule']['self_display_name'],
                                        'MemberNumber' => $Player['MemberNumber']
                                    ),
                                    array(
                                        'Tag' => 'TargetCharacter',
                                        'Text' => $display_name,
                                        'MemberNumber' => $data['SourceMemberNumber']
                                    ),
                                    array(
                                        'Tag' => 'ActivityGroup',
                                        'Text' => 'ItemHead'
                                    ),
                                    array(
                                        'Tag' => 'ActivityName',
                                        'Text' => 'Pet'
                                    ),
                                    array(
                                        'Tag' => 'nonce',
                                        'Text' => mt_rand()
                                    )
                                )
                            ));
                
                if(IsRoomadmin($Chatroom['Admin'])){
                    logMessage('嘗試恢复房間管理員名單');
                    foreach($GLOBALS['CustomValue']['Owner'] as $sp){
                        AddRoomAdmin($con,$sp);
                    }
                }

                //歡迎回家!
                if(in_array($data['SourceMemberNumber'],$Chatroom['Admin'])){
                    logMessage($data['SourceMemberNumber'].'[房間管理員]進入了房間');
                    //仅限房管
                    $display_name = $data['Character']['Nickname'];
                    if($data['Character']['Nickname'] == '') $display_name = $data['Character']['Name'];

                    emit($con,'ChatRoomChat',array(
                        'Sender' => $Player['MemberNumber'],
                        'Content' => 'Beep',
                        'Type' => 'Action',
                        'Dictionary' => array(
                            array(
                                'Tag' => 'Beep',
                                'Text' => '歡迎回家~ '.$display_name
                            ),
                            array(
                                'Tag' => 'nonce',
                                'Text' => mt_rand()
                            )
                        )
                    ));
                }
                
                //触发判斷
                if(IsRoomadmin($Chatroom['Admin'])){
                    //ban掉拉黑的用户
                    setRoomBlackList($con,$Chatroom['Ban']);
                }
                
                break;
            case 'ChatRoomMessage':
                switch ($data['Type']) {
                    case 'Action':
                        switch ($data['Content']) {
                            case 'ServerEnter':
                                //這是做什麽的? 忘記惹!                                
                                break;
                            case 'Beep':
                                //Action Beep是個好東西! Game内/action指令发送的就是它
                                break;
                            case 'SlowLeaveAttempt':
                            
                                //有人打算慢慢走? 來人 幫忙架出去!
                                if(IsRoomadmin($Chatroom['Admin'])){
                                    //差評如潮 關閉了
                                    //首先得是房管
                                    /*
                                    //發條消息確認一下
                                    emit($con,'ChatRoomChat',array(
                                        'Sender' => $Player['MemberNumber'],
                                        'Content' => 'Beep',
                                        'Type' => 'Whisper'
                                    ));
                                    $GLOBALS['SystemVaule']['Chatroom']['HelpLeave'] = array(
                                        'status' => true,
                                        'target' => $data['Dictionary'][0]['MemberNumber']
                                    );
                                    */
                                    //踢掉~
                                    //emit($con,'ChatRoomAdmin',array(
                                    //    'Action' => 'Kick',
                                    //    'MemberNumber' => $data['Sender']
                                    //));

                        /*
                            emit($con,'ChatRoomChat',array(
                                        'Sender' => $Player['MemberNumber'],
                                        'Content' => 'Beep',
                                        'Type' => 'Action',
                                        'Dictionary' => array(
                                            array(
                                                'Tag' => 'Beep',
                                                'Text' => $GLOBALS['SystemVaule']['self_display_name'].'帮助'.$data['Dictionary'][0]['Text'].'離開了房間.'
                                            ),
                                            array(
                                                'Tag' => 'nonce',
                                                'Text' => mt_rand()
                                            )
                                        )
                                    ));
                        */
                                }
                                break;

                        }
                        break;
                    case 'Chat':
                        //一般信息
                        //"Content"就是信息
                        logMessage('[Chat]'.$data['Sender'].':'.$data['Content']);

                        //Candy~ Candy~ 哦, 我不是Candy, 那沒事了x
                        //特意设置的如出一轍的判斷方式
                        if(is_int(strpos($data['Content'],'candy'))){
                            //发送Action Beep
                            /*
                            本來是誤解向的, 結果完全沒人觸發 没意思了
                            
                            emit($con,'ChatRoomChat',array(
                                'Sender' => $Player['MemberNumber'],
                                'Content' => 'Beep',
                                'Type' => 'Action',
                                'Dictionary' => array(
                                    array(
                                        'Tag' => 'Beep',
                                        'Text' => '歡迎使用不是堪蒂的也不神秘的"小道具"'
                                    ),
                                    array(
                                        'Tag' => 'nonce',
                                        'Text' => mt_rand()
                                    )
                                )
                            ));
                            */
                        }

                        break;
                    case 'Whisper':
                        //有人发Whisper? 好耶!
                        logMessage('[Whisper]'.$data['Sender'].':'.$data['Content']);
                        //["ChatRoomChat",{"Content":"114","Type":"Whisper","Target":0}]
                        //["ChatRoomMessage",{"Sender":1,"Content":"1","Type":"Whisper"}]
                        emit($con,'ChatRoomChat',array(
                            'Type' => 'Whisper',
                            'Target' => $data['Sender'],
                            'Content' => $data['Content']
                        ));
                        

                        break;
                    case 'Emote':
                        //這個... 還是不處理了吧~
                        logMessage('[Emote]'.$data['Sender'].':'.$data['Content']);
                        /*
                        [
                        "ChatRoomChat",
                        {
                            "Content": "1",
                            "Type": "Emote",
                            "Dictionary": [
                            {
                                "Tag": "fbc_nonce",
                                "Text": 122
                            }
                            ]
                        }
                        ]
                        */
                        break;
                    case 'Hidden':
                        //BCX的最爱, 一秒三條
                        break;
                }
                break;

            case 'ChatRoomSyncItem':
                //["ChatRoomSyncItem", {"Source": 來源, "Item": {"Target": 目标, "Group": "ItemNeckRestraints", "Name": "ChainLeash", "Color": "Default", "Difficulty": 10}}]
                //ChatRoomCharacterItemUpdate

                //如果在角色的白名單内即允許更新物品 否则用登入时保存的Appearance覆蓋掉
                if(!CharacterIsWhitelist($data['Source'])){
                    emit($con,'AccountUpdate',array(
                        'AssetFamily' => 'Female3DCG',
                        'Appearance' => $GLOBALS['SystemVaule']['Appearance']
                    ));
                    emit($con,'ChatroomCharacterUpdate',array(
                        //ID是OnlineID
                        'ID' => $Player['ID'],
                        'ActivePose' => null,
                        'Appearance' => $GLOBALS['SystemVaule']['Appearance']
                    ));
                }

                break;

            case 'ChatRoomCreateResponse':
                //創建房間
                switch ($data) {
                    case 'ChatRoomCreated':
                        logMessage('成功的創建了房間');
                        break;
                    default:
                            //如果不在房管列表就把自己追加進去
                            if(!in_array($Player['MemberNumber'], $Player['LastChatRoomAdmin'])){
                                array_push($Player['LastChatRoomAdmin'],$GLOBALS['CustomValue']['Owner']);
                                $Player['LastChatRoomAdmin'][] = $Player['MemberNumber'];
                            }
                            //創建房間
                            emit($con,'ChatRoomCreate',array(
                                'Name' => $GLOBALS['SystemVaule']['TryToCreateRoom']['RName'],
                                'Background' => 'SpaceCaptainBedroom',
                                'Private' => true,
                                'Locked' => false,
                                'Game' => '',
                                'Limit' => 10,
                                'Language' => 'CN',
                                'Description' => 'Chika创建的房间, 等待占领!',
                                'Admin' => array($Player['MemberNumber'],$data['MemberNumber']),
                                'Ban' => array(),
                                'BlockCategory' => array()
                            ));
                        break;
                }
                break;

            case 'ChatRoomSyncRoomProperties':
                logMessage('房間配置被更新');
                //房間配置被更新了? 那咱也要保存一份最新的到賬戶呢!
                $Player = array_merge(array(
                    'LastChatRoom' => $data['Name'],
                    'LastChatRoomBG' => $data['Background'],
                    'LastChatRoomPrivate' => $data['Private'],
                    'LastChatRoomSize' => $data['Limit'],
                    'LastChatRoomLanguage' => $data['Language'],
                    'LastChatRoomDesc' => $data['Description'],
                    'LastChatRoomAdmin' => $data['Admin'],
                    'LastChatRoomBan' => $data['Ban'],
                    'LastChatRoomBlockCategory' => $data['BlockCategory']
                ),$Player);
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
        }

    };

    $con->onClose = function(AsyncTcpConnection $con) {
        //稍等一下再進行重连, 避免Python部分卡入循環
        sleep(2);
        $con->connect();
    };

    $con->connect();
};

Worker::runAll();

function emit($con,$event,$data){
    //類似ServerSend()
    $data_sent = json_encode(array($event,$data),JSON_UNESCAPED_UNICODE);
    //echo $data_sent.PHP_EOL;
    $con->send($data_sent);
}
function ChatroomJoin($roomname,$con){
    //集成了一下
    $GLOBALS['SystemVaule']['TryJoinChatroom'] = $roomname;
    emit($con,'ChatRoomJoin',array('Name'=>$roomname));
}
function CharacterIsAdmin($SoucreMember){
    //方便判断
    return in_array($SoucreMember,$GLOBALS['CustomValue']['Owner']);
}
function CharacterIsWhitelist($SoucreMember){
    //同上
    global $Player;
    return in_array($SoucreMember,$Player['WhiteList']);
}
function AddCharacter2Whitelist($SoucreMember,$con){
    //可能會用多次, 所以集成一下
    global $Player;
    $Player['WhiteList'][] = $SoucreMember;
    emit($con,'AccountUpdate',array('WhiteList'=>$Player['WhiteList']));
}
function ServerAppearanceBundle($Appearance){
    //完全搬运JavaScript的
    var_dump($Appearance[2]);
    $Bundle = array();
    for ($A = 0; $A < count($Appearance); $A++) {
        $N = array();
        $N['Group'] = $Appearance[$A]['Asset']['Group']['Name'];
        $N['Name'] = $Appearance[$A]['Asset']['Name'];

        if (!empty($Appearance[$A]['Color']) and $Appearance[$A].Color !== "Default") $N['Color'] = $Appearance[A]['Color'];
        if (!empty($Appearance[$A]['Difficulty']) and $Appearance[$A].Difficulty !== 0) $N['Difficulty'] = $Appearance[A]['Difficulty'];
        if (!empty($Appearance[$A]['Property'])) $N['Property'] = $Appearance[$A]['Property'];
        if (!empty($Appearance[$A]['Craft'])) $N['Craft'] = $Appearance[$A]['Craft'];

        $Bundle[] = $N;
    }
    return $Bundle;

}
function LZString_decompressFromUTF16($str){
    //PHP能不能有個好用的Lib? 怎么都要用Python來處理啊啊啊啊啊 哭哭;;
    $filename = mt_rand().'.txt';
    file_put_contents($filename,$str);
    //提交運行 隨後取回數據
    exec('python3 lz_string.py '.$filename);
    $content = file_get_contents($filename);
    unlink($filename);
    return $content;
}
function CharacterDecompressWardrobe($Wardrobe) {
    //解压衣柜
    if(is_string($Wardrobe)) {
        $CompressedWardrobe = json_decode(LZString_decompressFromUTF16($Wardrobe),true);
        $DecompressedWardrobe = array();
        if (!is_null($CompressedWardrobe)) {
            for ($W = 0; $W < count($CompressedWardrobe); $W++) {
                $Arr = array();
                for ($A = 0; $A < count($CompressedWardrobe[$W]); $A++)
                    $Arr[] = array(
                        'Name' => $CompressedWardrobe[$W][$A][0],
                        'Group' => $CompressedWardrobe[$W][$A][1],
                        'Color' => $CompressedWardrobe[$W][$A][2],
                        'Property' => $CompressedWardrobe[$W][$A][3],
                    );
                $DecompressedWardrobe[] = $Arr;
            }
        }
        return $DecompressedWardrobe;
    }
    return $Wardrobe;
}
function MNgetArray($MemberNumber){
    //感覺沒什麽用, 就先這樣吧
    global $Chatroom;
    foreach ($Chatroom['Character'] as $Key => $C){
        if($C['MemberNumber'] == $MemberNumber){
            return $Key;
        }
    }
    return -1;
}
function IsinChatroom($MemberNumber){
    if(MNgetArray($MemberNumber) !== -1) return true;
    return false;
}
function setRoomBlackList($con,$BlackList){
    //設置房間的Ban List 已存在就不用加進去了, 不存在的就進行追加
    foreach ($GLOBALS['CustomValue']['BlackList'] as $SinglePerson){
        if(!in_array($SinglePerson, $BlackList)){
            $BlackList[] = $SinglePerson;
            //触发更新
            emit($con,'ChatRoomAdmin',array(
                'Action' => 'Ban',
                'MemberNumber' => $SinglePerson
            ));
        }
    }
    return $BlackList;
}
function IsRoomadmin($AdminList){
    //查询是否爲房間管理員
    global $Player;
    return in_array($Player['MemberNumber'],$AdminList);
}
function AddRoomAdmin($con,$SinglePerson){
    //触发更新
    emit($con,'ChatRoomAdmin',array(
        'Action' => 'Promote',
        'MemberNumber' => $SinglePerson
    ));
}

//AI寫的這玩意, 能用就是勝利
// Create a PHP log system that directly echoes to stdout and optionally saves to a file
function logMessage($message, $logFile = null) {
    $timestamp = date("Y-m-d H:i:s");
    $formattedMessage = "[$timestamp] $message" . PHP_EOL;
    echo $formattedMessage;
    if ($logFile !== null) {
        file_put_contents($logFile, $formattedMessage, FILE_APPEND);
    }
}

// Usage example:
//logMessage("This is a log message.", "log.txt"); // Save to file
//logMessage("This is a log message."); // Only echo to stdout
