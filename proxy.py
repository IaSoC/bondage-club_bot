import asyncio,socketio,threading,json,os,signal
from websocket_server import WebsocketServer

sio = socketio.AsyncClient(reconnection=True)


@sio.event
async def connect():
    print('connected to socket.io server')


@sio.event
async def disconnect():
    print('disconnected from socket.io server')
    server.send_message_to_all("[\"ForceDisconnect\",\"DisconnectServer\"]")


@sio.on('*')
async def catch_all(event, data):
    server.send_message_to_all(json.dumps([event,data]))
    if event == 'ForceDisconnect':
        print('Another Device connect to the server, Disconnected.')

    print("Receive: %s" % (event))


async def start_server():
    await sio.connect('https://bondage-club-server.herokuapp.com/',headers = {'Origin':'https://bondageprojects.elementfx.com'})
    await sio.wait()

class WSserver (threading.Thread):
    def __init__(self, threadID, name, delay):
        threading.Thread.__init__(self)
        self.threadID = threadID
        self.name = name
        self.delay = delay
    def run(self):
        
        server.set_fn_new_client(new_client)
        server.set_fn_client_left(client_left)
        server.set_fn_message_received(message_received)
        server.run_forever()

# Called for every client connecting (after handshake)
def new_client(client, server):
    print("New client connected")
    if client['id'] != 1 :
        print("OverConnect, shutdown.")
        os.kill(os.getpid(),signal.SIGINT)


# Called for every client disconnecting
def client_left(client, server):
    print("Client disconnected and service will shutdown,please setup a autorestart bash.")
    os.kill(os.getpid(),signal.SIGINT)


# Called when a client sends a message
def message_received(client, server, message):
    #if len(message) > 200:
    #    message = message[:200]+'..'
    msg = json.loads(message)
    asyncio.run(sio.emit(msg[0],msg[1]))
    print("Sent: %s" % (msg[0]))
        



if __name__ == '__main__':
    server = WebsocketServer(host='127.0.0.1', port=13254)
    thread1 = WSserver(1, "WS_Server", 1)
    thread1.daemon = True
    thread1.start()

    asyncio.run(start_server())
