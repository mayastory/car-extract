# Protocol packets (v1)

All realtime packets travel over WebSocket as JSON.

## Client -> Server
- `hello` `{ t: 'hello', token: string, client: { name?: string, ver?: string } }`
- `move` `{ t: 'move', dir: 0|1|2|3 }`  // 0=down 1=up 2=left 3=right
- `ping` `{ t: 'ping', c: number }`

## Server -> Client
- `welcome` `{ t: 'welcome', tick: number, player: { id: number, map: string, x:number, y:number, dir:number } }`
- `move_result` `{ t:'move_result', ok:boolean, tick:number, x?:number, y?:number, dir?:number, reason?:string }`
- `state` `{ t: 'state', tick: number, entities: Array<{ id:number, x:number, y:number, dir:number }> }`
- `pong` `{ t: 'pong', c: number, s: number }`
- `error` `{ t: 'error', code: string, detail?: any }`
