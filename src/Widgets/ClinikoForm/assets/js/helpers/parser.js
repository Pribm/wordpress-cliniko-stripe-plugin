export function extractNestedFields(form, parentKey) {
  const formData = new FormData(form);
  const result = {};

  for (const [key, value] of formData.entries()) {
    const match = key.match(new RegExp(`^${parentKey}\\[([^\\]]+)\\]$`));
    if (match) {
      const field = match[1];
      if (!result[parentKey]) result[parentKey] = {};
      result[parentKey][field] = value;
    }
  }
  return result;
}

export function parseFormToStructuredBody(formEl, formHandlerData) {
  const formData = new FormData(formEl);
  const sectionsData = formHandlerData.sections || [];

  const structured = {
    content: {
      sections: sectionsData
        .map(section => {
          const questions = section.questions
            .map(q => {
              const question = {
                name: q.name,
                type: q.type,
                required: !!q.required,
              };

              if (q.type === "checkboxes" && Array.isArray(q.answers)) {
                const rawSelected = formData.getAll(q.name + "[]") || [];
                question.answers = q.answers.map(opt => {
                  const entry = { value: opt.value };
                  if (rawSelected.includes(opt.value)) entry.selected = true;
                  return entry;
                });
                if (q.other?.enabled) {
                  const otherChecked =
                    rawSelected.includes("__other__") ||
                    rawSelected.includes("other");
                  const otherValue = (formData.get(q.name + "_other") || "").trim();
                  question.other = otherChecked
                    ? { value: otherValue, enabled: true, selected: true }
                    : { enabled: true };
                }
              } else if (q.type === "radiobuttons" && Array.isArray(q.answers)) {
                const selected = formData.get(q.name);
                question.answers = q.answers.map(opt => {
                  const entry = { value: opt.value };
                  if (selected === opt.value) entry.selected = true;
                  return entry;
                });
                if (q.other?.enabled) {
                  const isOther = selected === "__other__" || selected === "other";
                  const otherValue = (formData.get(q.name + "_other") || "").trim();
                  question.other = isOther
                    ? { value: otherValue, enabled: true, selected: true }
                    : { enabled: true };
                }
              } else {
                question.answer = formData.get(q.name);
              }
              return question;
            })
            .filter(q => {
              if (q.type === "signature") return false;
              if (q.answers?.length === 0) return false;
              if ("answer" in q && typeof q.answer === "string" && q.answer.trim() === "")
                return false;
              return true;
            });

          return {
            name: section.name,
            description: section.description,
            questions,
          };
        })
        .filter(section => section.questions.length > 0),
    },
    ...extractNestedFields(formEl, "patient"),
  };

  return structured;
}
