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

### 0. Explore & Plan Before Coding

**Do not start writing code immediately.** Take the time to:

- **Explore the existing codebase**: Read all relevant existing files — evaluators, services, models, config, tests, interfaces, DTOs. Use subagents for thorough parallel exploration. Understand how existing implementations work before designing new ones.
- **Build a detailed implementation plan**: Present it to the user in a structured format with phases, files to create/modify, dependencies between phases, and design decisions. Get explicit approval before writing any code.
- **Identify patterns to follow**: Study sibling files for conventions (naming, structure, test helpers, factory usage). Mirror existing patterns exactly.

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

### 4. Implement in Phases with Parallel Batching

Follow the step file's deliverable order, but **batch independent work**:

- Create all independent files in a single tool call batch (e.g., all evaluator classes together, all test files together)
- Create all independent edits with `multi_replace_string_in_file` in a single call
- Only run terminal commands sequentially — never parallelize terminal operations

**Typical phase sequence:**

1. **Foundation**: Registry classes, constants, configuration changes — no dependencies
2. **Implementations**: Services, evaluators, resolvers — depend on foundation, but independent of each other (create in parallel)
3. **Wiring**: Config updates, catalog changes, validator extensions — depend on implementations
4. **Tests**: Write alongside or immediately after their subject files; batch all test files in one call
5. **Verify**: Run tests, check for regressions in existing tests
6. **Document**: Update the plan file to mark the step as ✅ Implemented with full details
7. **Format**: Run `vendor/bin/pint --dirty`

### 5. Validate

- Run tests with `php artisan test --compact` filtered to the new/modified test files
- **Run existing related tests** to verify no regressions (e.g., existing evaluator tests after adding new evaluators)
- Run the full test suite to confirm no unrelated breakage
- Ensure all tests pass before considering the step done
- Run `vendor/bin/pint --dirty` to fix formatting

### 6. Document & Handoff

After implementation is complete and tests pass:

- **Update the plan file** (`DAILY_CHALLENGE_PLAN.md`): Mark the step as `✅ Implemented` with full details following the pattern of prior steps (overview, files created/modified, architecture, test summary, design decisions)
- **Provide a planner-ready summary**: A concise bullet-point summary covering: what was done, evaluator/component mappings, key architectural decisions, files created/modified, test counts, and what's ready for the next step. This keeps the planner LLM aligned without re-reading the full plan file.

## Key Principles

- **Plan first, code second**: Always present a structured plan before writing code. Get user approval on architecture, file locations, design decisions, and test strategy. This prevents misalignment and rework.
- **Respect scope boundaries strictly**: If the step says "Do NOT implement X", don't implement X even if it seems natural
- **One vertical slice**: The step may ask for only one evaluator/implementation to validate the pattern before expanding
- **Pure evaluation, separate persistence**: Keep calculation logic separate from database mutations
- **Idempotency**: If the step requires it, ensure duplicate processing is handled
- **Test all paths**: Happy path, failure path, edge cases — as specified in the step file
- **Parallel writes, sequential runs**: Create/edit independent files in parallel batches. Never run terminal commands in parallel — chain them.
- **Plan file is living documentation**: After implementation, update the plan file to mark the step ✅ Implemented with full details (files created/modified, test counts, design decisions). Future steps depend on this accuracy.

## Common Pitfalls

- **Coding without a plan**: Jumping straight to implementation without exploring the existing codebase and presenting a plan. This leads to architectural mismatches, missed edge cases, and rework.
- **Implementing future steps**: Reading the plan file creates temptation to "just add" things from later steps. Don't.
- **Skipping the out-of-scope list**: The step file's exclusions are as important as its inclusions
- **Ignoring architectural decisions**: The step file may mandate specific patterns (interface-first, DTO usage, registry pattern) — follow them exactly
- **Tests after formatting**: Run pint before committing, but make sure tests pass first
- **Forgetting regression checks**: After implementing, always run existing related tests (not just new ones) to confirm nothing broke
- **Neglecting the plan file update**: Failing to document the step's implementation in the plan file leaves future steps (and the planner LLM) with stale information
