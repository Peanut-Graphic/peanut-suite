# Peanut Suite Accessibility

## Commitment to Accessibility

Peanut Suite is committed to digital accessibility for all users. We strive to ensure that our WordPress plugin and its user interface are usable by everyone, including people with disabilities.

## Accessibility Standards

Peanut Suite aims to conform to the **Web Content Accessibility Guidelines (WCAG) 2.1 Level AA** standard. This means our plugin should be:

- **Perceivable** — Information and UI components must be presentable to users in ways they can perceive
- **Operable** — UI components and navigation must be operable via keyboard and other input methods
- **Understandable** — Text and UI operations must be understandable to all users
- **Robust** — Content must be compatible with current and future assistive technologies

## Accessibility Features

### Visual Design
- High contrast color combinations meeting WCAG AA standards (4.5:1 for normal text)
- Responsive design that adapts to different screen sizes and zoom levels (up to 200%)
- No reliance on color alone to convey information
- Clear visual focus indicators on interactive elements

### Keyboard Navigation
- All functionality available via keyboard
- Logical tab order through interactive elements
- Skip links to jump over repetitive content
- Keyboard shortcuts documented and accessible

### Screen Readers
- Semantic HTML structure with proper heading hierarchy
- Alternative text for all images and icons
- Form labels associated with inputs via `for` attribute
- ARIA attributes used where necessary to enhance semantic meaning
- Error messages and validation feedback clearly associated with inputs

### Forms
- All form inputs have associated labels
- Required fields marked both visually and semantically
- Error messages linked to inputs via `aria-describedby`
- Helpful hints and instructions provided with context
- Form validation clear and easy to understand

### Interactive Components
- Buttons have accessible names
- Links have descriptive text content
- Dropdowns support keyboard navigation
- Modals manage focus properly
- Loading states don't obscure information

## Testing Process

### Automated Testing
We run automated accessibility tests on every pull request:

1. **ESLint with jsx-a11y** — Static analysis of React code for common accessibility issues
2. **Vitest with jest-axe** — Runtime testing of rendered components for WCAG violations
3. **GitHub Actions CI** — Accessibility pipeline runs on all PRs to main branch

### Manual Testing
Beyond automated tests, our team performs:

- Screen reader testing (NVDA, JAWS, VoiceOver)
- Keyboard navigation testing
- Color contrast verification
- Focus management review
- Zoom and magnification testing

## Running Tests Locally

### Prerequisites
```bash
cd frontend
npm ci
```

### Run Accessibility Tests
```bash
# Run once
npm run test:a11y

# Run in watch mode
npm run test:watch

# Or use the shell script
./scripts/a11y-check.sh
./scripts/a11y-check.sh --watch
```

### Run ESLint Checks
```bash
npx eslint --ext .js,.jsx,.ts,.tsx src/ \
  --rule 'jsx-a11y/alt-text: error' \
  --rule 'jsx-a11y/aria-props: error' \
  --rule 'jsx-a11y/aria-role: error'
```

## Component Library

All core components in the Peanut Suite component library have been built with accessibility in mind:

### Button
- Semantic `<button>` elements
- Accessible names via text content or `aria-label`
- Focus indicators on all variants
- Disabled state properly communicated

### Input
- Associated labels via `htmlFor`
- Error states with `aria-invalid`
- Error messages with `role="alert"`
- Hint text with `aria-describedby`
- Support for icons with `aria-hidden`

### Form Controls
- Select inputs with associated labels
- Textarea with proper semantics
- Checkbox and radio support
- All validation feedback accessible

### Layout
- Skip to main content link
- Proper heading hierarchy
- Semantic sections and landmarks
- Accessible navigation structures

### Tables
- Proper `<table>` semantics
- Header cells marked with `<th>`
- Row and column associations where needed
- Accessible sorting and pagination controls

## Known Limitations

### Color Vision Deficiency
While we strive for WCAG AA color contrast, some users with specific color vision deficiencies may find certain combinations challenging. We avoid relying on color alone and provide text labels and icons.

### Motion and Animation
Peanut Suite includes some animations (e.g., loading states, transitions). Users can disable animations via system preferences (`prefers-reduced-motion`). Some animations may still be present for visual feedback.

### PDF Generation
The PDF export feature uses html2canvas and jsPDF, which may not perfectly preserve accessibility features when converting from HTML to PDF. Exported PDFs may require additional accessibility review.

### Third-Party Dependencies
Some functionality depends on third-party libraries (Chart.js, React Router, etc.) that may have their own accessibility considerations.

## How to Report Accessibility Issues

Found an accessibility issue? Please report it to us:

1. **GitHub Issues** — Open an issue with the label `accessibility`
2. **Email** — Contact the development team directly
3. **WordPress Support** — Use the plugin support forums

When reporting, please include:
- Description of the issue
- Steps to reproduce
- Expected behavior
- Your assistive technology (if applicable)
- Browser and OS information

## Resources

### Web Accessibility
- [WCAG 2.1 Guidelines](https://www.w3.org/WAI/WCAG21/quickref/)
- [WebAIM Resources](https://webaim.org/)
- [A11ycasts by Google Chrome](https://www.youtube.com/watch?v=HtTyRajRuyY&list=PLNYkxOF6rcICWx0C9Xc-RgEzwLvsPrVmeR)

### React Accessibility
- [React Accessibility Documentation](https://reactjs.org/docs/dom-elements.html#dangerouslysetinnerhtml)
- [The A11Y Project](https://www.a11yproject.com/)

### Testing Tools
- [Axe DevTools](https://www.deque.com/axe/devtools/)
- [WAVE Browser Extension](https://wave.webaim.org/extension/)
- [NVDA Screen Reader](https://www.nvaccess.org/)

### Standards
- [WAI-ARIA Authoring Practices](https://www.w3.org/WAI/ARIA/apg/)
- [Inclusive Components](https://inclusive-components.design/)

## Accessibility Roadmap

Ongoing improvements to accessibility:

- [ ] Enhanced color contrast in dark mode
- [ ] Improved keyboard navigation documentation
- [ ] Screen reader testing with multiple tools
- [ ] Extended animation controls (prefers-reduced-motion)
- [ ] Accessibility audit with external expert
- [ ] User testing with people who have disabilities

## Changelog

### v4.1.6
- Initial accessibility CI pipeline
- Comprehensive component accessibility tests
- WCAG 2.1 AA conformance audit
- ESLint jsx-a11y rules enabled
- Vitest accessibility test suite configured
- GitHub Actions accessibility workflow

## Questions?

If you have questions about Peanut Suite's accessibility or need specific accommodations, please reach out to the development team. We're committed to making this plugin accessible to everyone.

---

**Last Updated:** 2026-03-28
**Maintained By:** Peanut Graphic Development Team
**Version:** 4.1.6+
