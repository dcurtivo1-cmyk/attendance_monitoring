(() => {
    const data = document.getElementById('student-sections-data');
    if (!data) return;
    const sectionsByGrade = JSON.parse(data.textContent);
    document.querySelectorAll('[data-student-grade]').forEach(grade => {
        const form = grade.closest('form');
        const section = form.querySelector('[data-student-section]');
        const help = form.querySelector('[data-section-help]');
        const submit = form.querySelector('[type="submit"]');
        const update = () => {
            const sections = sectionsByGrade[grade.value] || [];
            // Changing grades always clears the old section selection.
            const placeholder = !grade.value ? 'Choose a grade level first' : sections.length ? 'Choose a section' : 'No sections available';
            section.replaceChildren(new Option(placeholder, ''));
            sections.forEach(name => section.add(new Option(name, name)));
            section.disabled = !grade.value || sections.length === 0;
            submit.disabled = section.disabled;
            if (help) help.textContent = !grade.value ? 'Select a grade level to see its sections.' : sections.length ? `Showing sections for ${grade.value}.` : 'No section is assigned to this grade yet. Add a teaching assignment for this grade and section, then reload this page.';
        };
        grade.addEventListener('change', update);
        // Form reset applies default values after the reset event.
        form.addEventListener('reset', () => setTimeout(update, 0));
        window.addEventListener('pageshow', update);
        update();
    });
})();
