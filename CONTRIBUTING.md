# Contributor Guidelines

## General Requirements

- **Repository Structure**: This project maintains a single main branch for JTL-Shop 5 compatibility:
    - `master` - JTL-Shop 5.x (supports multiple PHP versions via compatibility testing)

- **Contribution Process**: All changes must be submitted via pull request after forking the repository

- **Branch Strategy**: Create feature branches from the `master` branch

## Code Quality Standards

Every commit must meet these requirements:

1. **Stable State**: Each commit should leave the codebase in a working, stable state
2. **Quality Assurance**: Run `composer run qa` - it must pass without errors
3. **Installation Tests**: The plugin must be installable and uninstallable with every commit
4. **JTL-Shop Version Compatibility**: Ensure compatibility across supported JTL-Shop 5.x versions, starting with 5.3.* (e.g. 5.3.4, 5.4.1, 5.5.3, 5.6.2, 5.7.2 and so on). We always test the latest patch.
5. **Don't break**: The existing functionality should not break 

## Commit Message Guidelines

Follow our commit message format:

### Structure
```
Title: Brief description in imperative mood (under 70 characters)

Body:
Explain WHY the changes are needed (4-5 sentences max).
Reference relevant issues, meetings, or discussions.
Keep lines under 70 characters for readability.
You are writing this text for a reviewer. Don't make his life hard.

For implementation details see [ISSUE-NUMBER].
```

### Requirements

**Title:**
- Use English and imperative language ("Add feature" not "Added feature")
- Answer "WHAT?" - describe what the commit does
- Keep under 70 characters
- No issue numbers in the title

**Body:**
- Explain "WHY?" - provide context for the changes
- Reference issues, emails, or meetings with specific identifiers
- Use professional, neutral language
- Break lines at ~70 characters
- Include 4-5 sentences maximum

**Example Good Commit:**

```
Add keyboard navigation support for autocomplete dropdowns

Enable users to navigate autocomplete suggestions using keyboard inputs
to improve accessibility compliance and user experience. Users can now
use Tab, Enter, and arrow keys to interact with address suggestions
without requiring mouse input.

This change addresses accessibility requirements outlined in WCAG 2.1
guidelines and moves the solution towards better EAA compatibility.

For implementation details see DEV-456. Related accessibility audit
findings documented in DEV-789.
```

### What to Avoid

- Vague titles like "fixed stuff" or "updates"
- Multiple unrelated changes in one commit
- Missing context about why changes were made
- Unprofessional language or jokes
- Lines exceeding 70 characters
- Mixing different types of changes (bug fixes + new features + refactoring)
- Adding fixes for previous commits. Just amend them yourself. Please.
- Too much text
- Technical details of the implementation, unless they are not understandable from reading the code

## JTL-Shop Version Considerations

**PHP Compatibility:**
- Support PHP 8.1 to 8.5 as tested in `test_php_versions.sh`
- Test at valid JTL/PHP intersections (e.g. JTL 5.3 supports PHP 8.1, JTL 5.7 adds PHP 8.5 and drops PHP 8.2)
- Test with all supported PHP versions

**JTL-Shop Version Support:**
- Test with latest patch versions of each feature release of JTL-Shop starting with 5.3.*
- Use appropriate PHPStan configuration files for each version
- Ensure plugin works across all supported JTL-Shop versions

## Pull Request Requirements

Before submitting your PR:

1. ✅ All commits follow the message guidelines above
2. ✅ `composer run qa` passes without errors
3. ✅ Plugin installs and uninstalls successfully
4. ✅ Feature branch created from `master` branch
5. ✅ JTL-Shop version compatibility tested (5.3.* - 5.7.*)
6. ✅ PHP version compatibility verified using `test_php_versions.sh`

## Quality Checklist

Use this checklist for each commit:

- [ ] Commit has clear, imperative title under 70 characters
- [ ] Body explains business reason/context for changes or why a particular approach was chosen, if its not obvious
- [ ] Professional language used throughout
- [ ] Lines broken at ~70 characters for readability
- [ ] References to relevant issues/meetings included
- [ ] Code passes `composer run qa`
- [ ] Plugin installation/uninstallation works
- [ ] Changes are logically grouped (not mixing unrelated modifications)
- [ ] There are no fixes for previous commits in new commits
- [ ] JTL-Shop version compatibility considered and tested

## Getting Help

If you're unsure about any of these requirements or need clarification on the commit message format, please ask in the issue comments before starting work. We're happy to provide guidance to ensure your contribution meets our standards.

---

*Note: These guidelines ensure code quality, maintainability, and a clear project history. Following them helps reviewers understand your changes and makes the codebase easier to maintain long-term.*

---
