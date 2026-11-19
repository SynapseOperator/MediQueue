const form = document.getElementById('directory-search');
if (form) {
    const input = document.getElementById('directory-keyword');
    const list = document.getElementById('directory-results');
    const count = document.getElementById('directory-count');

    function showMatches() {
        const keyword = input.value.trim().toLowerCase();
        let matches = 0;
        for (const item of list.children) {
            item.hidden = !keyword || !item.textContent.toLowerCase().includes(keyword);
            if (!item.hidden) matches++;
        }
        list.hidden = matches === 0;
        count.textContent = keyword ? (matches ? `找到 ${matches} 个结果` : '没有找到相关科室或医生。') : '';
    }

    input.addEventListener('input', showMatches);
    form.addEventListener('submit', event => {
        if (!input.value.trim()) return;
        event.preventDefault();
        showMatches();
        list.querySelector('li:not([hidden]) a')?.focus();
    });
}

