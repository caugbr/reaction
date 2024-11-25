
document.addEventListener('DOMContentLoaded', function() {
    rootEvent('.reaction a[data-reaction]', 'click', event => {
        event.preventDefault();
        const elem = event.target.closest('a');
        const wrap = event.target.closest('.reaction');
        const type = wrap.getAttribute('data-type');
        const id = wrap.getAttribute('data-id');
        const reaction = elem.getAttribute('data-reaction');
        if (type && id && reaction) {
            clickReaction(type, id, reaction);
        }
    });
});

function clickReaction(type, id, reaction) {
    let userName = '';
    if (reactionStr.loggedIn == 'no') {
        userName = getAuthorName();
        if (!userName) {
            userName = prompt(reactionStr.askName);
            if (!userName) {
                alert(reactionStr.rejectNoName);
                return;
            } else {
                setCookie(`comment_author_${reactionStr.hash}`, userName);
            }
        }
    }
    let data = { action: 'improve_reaction', type, id, reaction, user: userName };
    ajax(reactionStr.ajaxurl, data, 'POST')
        .then(res => res.text())
        .then(resp => {
            const elem = $single(`.reaction[data-id="${id}"][data-type="${type}"] a[data-reaction="${reaction}"]`);
            if (elem) {
                elem.closest('.reaction').innerHTML = resp;
            }
        });
}

function getAuthorName() {
    const ckName = `comment_author_${reactionStr.hash}`;
    let name = getCookie(ckName);
    return name ? name : false;
}

function getCookie(name) {
    const cookies = document.cookie.split('; ').reduce((acc, cookie) => {
        const [key, value] = cookie.split('=');
        acc[key] = decodeURIComponent(value);
        return acc;
    }, {});
    return cookies[name] || null;
}

function setCookie(name, value, days = 30) {
    const date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    const expires = "expires=" + date.toUTCString();
    document.cookie = `${name}=${encodeURIComponent(value)}; ${expires}; path=/`;
}
