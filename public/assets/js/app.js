// JavaScript vanilla do ContábilFlow (sem scripts inline: a CSP bloqueia).

document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    // Confirmação para ações destrutivas: <form data-confirm="mensagem">.
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
        event.preventDefault();
        return;
    }

    // Evita duplo clique: desabilita os botões depois que o envio começou.
    window.setTimeout(function () {
        form.querySelectorAll('button[type="submit"]').forEach(function (button) {
            button.disabled = true;
        });
    }, 0);
});
