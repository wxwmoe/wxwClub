<?php return [

    // 私信提醒，:club: 为群组名称，:hours: 为限流窗口小时数，:limit: 为对应的上限数值
    'limit-dupl'   => 'This one is the same as something you already sent to :club: within the last :hours: hours. Only :limit: identical post(s) are allowed, so it was not forwarded (´・ω・｀)',
    'limit-user'   => 'You have hit the limit of :limit: posts to :club: within :hours: hours. Impressive typing speed! This one takes a little nap, so please come back later ~',
    'limit-club'   => ':club: has taken in :limit: posts within :hours: hours and cannot hold any more for now. This one will have to wait a bit ~',
    'limit-site'   => 'Your instance has sent :limit: posts to :club: within :hours: hours. Everyone rushing in at once would clog things up, so this one waits its turn ~',
    'create-limit' => 'This instance has already created all the groups it can this hour, so the one you mentioned cannot open its doors yet. Try calling for it next hour ~'

];
