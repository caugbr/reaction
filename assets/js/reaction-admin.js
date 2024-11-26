
jQuery(document).ready(function ($) {
    $('#image_set').on('input', function() {
        $single('.reactions-order-inputs').innerHTML = '';
    });
    $('.reaction-list input').on('input', function() {
        const input = this;
        const img = input.nextElementSibling.cloneNode();
        if (input.checked) {
            const label = tag('label', {}, img);
            $single('.reactions-order').appendChild(label);
        } else {
            const im = $single(`.reactions-order img[src="${img.src}"]`);
            if (im) {
                const label = im.closest('label');
                label.parentElement.removeChild(label);
            }
        }
        addRemoveInputs();
    });

    $('.reactions-order').sortable({
        items: '> label',
        placeholder: "ui-state-highlight",
        stop: addRemoveInputs
    }).disableSelection();

    $('.reaction-form .pub-types input').on('input', showHidePositions);
    showHidePositions();
});

function showHidePositions() {
    const elems = $list('.reaction-form .pub-types input');
    const postPos = $single('#post_pos');
    postPos.style.display = 'none';
    Array.from(elems).forEach(e => {
        if (e.value == 'comment') {
            $single('#comment_pos').style.display = e.checked ? 'block' : 'none';
            return true;
        }
        if (e.checked) {
            postPos.style.display = 'block';
        }
    });
}

function addRemoveInputs() {
    const imgs = $list('.reactions-order img');
    const orderWrap = $single('.reactions-order-inputs');
    orderWrap.innerHTML = '';
    Array.from(imgs).forEach(img => {
        const id = img.src.split('/').pop().split('.').shift();
        const inp = tag('input', {
            type: 'hidden',
            value: id,
            id: `active_order_${id}`,
            name: 'active_order[]'
        });
        orderWrap.appendChild(inp);
    });
}