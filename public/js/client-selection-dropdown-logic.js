// Client typeahead
var ci = document.getElementById('clientInput');
var cid = document.getElementById('clientId');
var sug = document.getElementById('clientSuggest');

ci.addEventListener('input', function () {
    cid.value = '';
    var t = this.value.trim();

    if (!t) {
        sug.style.display = 'none';
        sug.innerHTML = '';
        return;
    }

    fetch('/?page=clients-search&term=' + encodeURIComponent(t))
        .then(r => r.json())
        .then(list => {

            if (!Array.isArray(list) || list.length === 0) {
                sug.style.display = 'none';
                sug.innerHTML = '';
                return;
            }

            sug.innerHTML = list.map(x => `<div data-id="${x.id}" data-name="${x.name}" data-taxexempt="${x.tax_exempt_file || ''}" style=\"padding:8px 10px;cursor:pointer\">${x.name}</div>`).join('');

            Array.from(sug.children).forEach(el => {
                el.addEventListener('click', function () {
                    ci.value = this.dataset.name;
                    cid.value = this.dataset.id;

                    sug.style.display = 'none';
                });
            });
            sug.style.display = 'block';
        }).catch((e) => {
            console.log(e);
            sug.style.display = 'none'
        });
});

document.addEventListener('click', function (e) {
    if (!sug.contains(e.target) && e.target !== ci) {
        sug.style.display = 'none';
    }
});