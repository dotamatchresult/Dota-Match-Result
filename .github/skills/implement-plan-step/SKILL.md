---
name: implement-plan-step
description: 'Implement one step from a user-written implementation guide markdown file. Use when: user provides a step-specific spec file (e.g., DAILY_CHALLENGE_STEP_4.md) with a plan file for context, and wants only that step implemented. Other steps are reserved for separate sessions.'
argument-hint: '[step-file-path] [plan-file-path]'
user-invocable: true
---

# Implement Plan Step

Implements exactly one step from a multi-step feature plan. The user provides a step-specific guide and a reference plan — only the specified step is in scope.

## When to Use

- User attaches or references a step file like `docs/features/prompts/DAILY_CHALLENGE_STEP_4.md`
- User references a plan file like `docs/features/DAILY_CHALLENGE_PLAN.md` for context
- User says "only implement this step" or "other steps will be done separately"
- The task involves multiple interdependent deliverables (migrations, services, jobs, tests) scoped to one phase

## Procedure

### 1. Load Context

Read both files thoroughly:

- **Step File**: The primary spec — contains scope, architectural decisions, deliverables, and exclusions
- **Plan File**: Reference only — read to understand the overall architecture, data models, and already-implemented steps (marked with ✅)

### 2. Identify Scope Boundaries

Extract from the step file:

| Aspect | Action |
|--------|--------|
| **In Scope** | Every deliverable listed; every "Create/Implement" instruction |
| **Out of Scope** | Anything under "Do NOT implement" or belonging to future steps |
| **Architectural Decisions** | Patterns, interfaces, contracts, and constraints the step mandates |
| **Tests Required** | All test cases listed — write them all |

Do NOT implement anything from the Plan File that belongs to steps not mentioned as already done.

### 3. Follow Project Conventions

Apply all project conventions from `copilot-instructions.md`:

- Use `php artisan make:` commands with `--no-interaction`
- Prefer Eloquent over raw queries; use relationship methods with return type hints
- Use Form Requests for validation, not inline controller validation
- Use queued jobs with `ShouldQueue` for async work
- Write Pest tests; use `php artisan make:test --pest`
- Use PHP 8 constructor property promotion
- Add explicit return type declarations
- Use `casts()` method on models

### 4. Implement in Sequence

Follow the step file's deliverable order. Typical sequence:

1. **Database**: Run migrations first
2. **Contracts/Interfaces**: Create interfaces before implementations
3. **DTOs/Value Objects**: Create data objects needed by contracts
4. **Implementations**: Services, evaluators, resolvers
5. **Jobs**: Queued jobs that wire everything together
6. **Integration Points**: Hook into existing code (dispatch jobs, etc.)
7. **Tests**: Write tests last, then run them
8. **Format**: Run `vendor/bin/pint --dirty`

### 5. Validate

- Run tests with `php artisan test --compact` filtered to the new/modified test files
- Ensure all tests pass before considering the step done
- Run `vendor/bin/pint --dirty` to fix formatting

## Key Principles

- **Respect scope boundaries strictly**: If the step says "Do NOT implement X", don't implement X even if it seems natural
- **One vertical slice**: The step may ask for only one evaluator/implementation to validate the pattern before expanding
- **Pure evaluation, separate persistence**: Keep calculation logic separate from database mutations
- **Idempotency**: If the step requires it, ensure duplicate processing is handled
- **Test all paths**: Happy path, failure path, edge cases — as specified in the step file

## Common Pitfalls

- **Implementing future steps**: Reading the plan file creates temptation to "just add" things from later steps. Don't.
- **Skipping the out-of-scope list**: The step file's exclusions are as important as its inclusions
- **Ignoring architectural decisions**: The step file may mandate specific patterns (interface-first, DTO usage, registry pattern) — follow them exactly
- **Tests after formatting**: Run pint before committing, but make sure tests pass first
