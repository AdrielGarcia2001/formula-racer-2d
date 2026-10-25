import WebSocket from "ws";
const key = process.env.REVERB_APP_KEY;
const ws = new WebSocket(`ws://127.0.0.1:8080/app/${key}?protocol=7&client=ws-check&version=8.6.0`);
const names = [];
ws.on("message", (raw) => {
  const f = JSON.parse(raw.toString());
  if (f.event === "pusher:connection_established") ws.send(JSON.stringify({event:"pusher:subscribe",data:{channel:"race.1"}}));
  else if (!String(f.event).startsWith("pusher")) names.push(f.event);
});
setTimeout(() => { ws.close(); console.log("eventos:", JSON.stringify([...new Set(names)])); console.log(names.includes("race.chat") ? "CHAT WS: OK" : "CHAT WS: NO LLEGO"); process.exit(0); }, 6000);