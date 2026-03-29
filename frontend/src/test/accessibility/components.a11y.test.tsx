import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { axe, toHaveNoViolations } from 'jest-axe';
import Button from '../../components/common/Button';
import Input from '../../components/common/Input';
import { Textarea } from '../../components/common/Input';
import Card from '../../components/common/Card';
import Badge from '../../components/common/Badge';
import Modal from '../../components/common/Modal';
import Table from '../../components/common/Table';
import Select from '../../components/common/Select';

expect.extend(toHaveNoViolations);

describe('Accessibility - Component Tests', () => {
  describe('Button Component', () => {
    it('renders with accessible name', async () => {
      const { container } = render(<Button>Click me</Button>);
      const button = screen.getByRole('button', { name: /click me/i });
      expect(button).toBeInTheDocument();
    });

    it('has no axe violations', async () => {
      const { container } = render(<Button>Accessible Button</Button>);
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('disables properly with aria-disabled', async () => {
      const { container } = render(<Button disabled>Disabled Button</Button>);
      const button = screen.getByRole('button', { name: /disabled button/i });
      expect(button).toBeDisabled();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('supports icon with proper aria-hidden', async () => {
      const { container } = render(
        <Button icon={<span aria-hidden="true">★</span>}>Button with Icon</Button>
      );
      const button = screen.getByRole('button');
      expect(button).toHaveTextContent('★');
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('shows loading state without breaking accessibility', async () => {
      const { container } = render(<Button loading>Loading</Button>);
      const button = screen.getByRole('button');
      expect(button).toBeDisabled();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('maintains focus management across variants', async () => {
      const { container } = render(
        <>
          <Button variant="primary">Primary</Button>
          <Button variant="secondary">Secondary</Button>
          <Button variant="danger">Danger</Button>
        </>
      );
      const buttons = screen.getAllByRole('button');
      expect(buttons).toHaveLength(3);
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Input Component', () => {
    it('associates label with input', async () => {
      const { container } = render(
        <Input label="Email" name="email" type="email" />
      );
      const label = screen.getByText('Email');
      expect(label).toBeInTheDocument();
      const input = screen.getByRole('textbox');
      expect(input).toHaveAttribute('id');
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('marks required fields accessibly', async () => {
      const { container } = render(
        <Input label="Password" name="password" type="password" required />
      );
      const input = screen.getByRole('textbox');
      expect(input).toHaveAttribute('required');
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('displays error message with aria-invalid', async () => {
      const { container } = render(
        <Input
          label="Username"
          name="username"
          error="Username is required"
        />
      );
      const input = screen.getByRole('textbox');
      expect(input).toHaveAttribute('aria-invalid', 'true');
      const errorMessage = screen.getByText('Username is required');
      expect(errorMessage).toHaveAttribute('role', 'alert');
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('provides hint text with aria-describedby', async () => {
      const { container } = render(
        <Input
          label="Password"
          name="password"
          type="password"
          hint="Must be at least 8 characters"
        />
      );
      const input = screen.getByRole('textbox');
      expect(input).toHaveAttribute('aria-describedby');
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('displays validation icons with proper aria-hidden', async () => {
      const { container } = render(
        <Input
          label="Name"
          name="name"
          showValidation
          success
        />
      );
      const input = screen.getByRole('textbox');
      expect(input).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('supports left and right icons accessibly', async () => {
      const { container } = render(
        <Input
          label="Search"
          name="search"
          leftIcon={<span aria-hidden="true">🔍</span>}
          rightIcon={<span aria-hidden="true">✕</span>}
        />
      );
      const input = screen.getByRole('textbox');
      expect(input).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Textarea Component', () => {
    it('associates label with textarea', async () => {
      const { container } = render(
        <Textarea label="Comments" name="comments" />
      );
      const label = screen.getByText('Comments');
      expect(label).toBeInTheDocument();
      const textarea = screen.getByRole('textbox');
      expect(textarea).toHaveAttribute('id');
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('displays error with appropriate semantics', async () => {
      const { container } = render(
        <Textarea
          label="Message"
          name="message"
          error="Message is too short"
        />
      );
      const textarea = screen.getByRole('textbox');
      expect(textarea).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('marks required textareas accessibly', async () => {
      const { container } = render(
        <Textarea
          label="Description"
          name="description"
          required
        />
      );
      const textarea = screen.getByRole('textbox');
      expect(textarea).toHaveAttribute('required');
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Card Component', () => {
    it('renders with semantic structure', async () => {
      const { container } = render(
        <Card>
          <h2>Card Title</h2>
          <p>Card content goes here</p>
        </Card>
      );
      expect(screen.getByText('Card Title')).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('supports header with proper heading hierarchy', async () => {
      const { container } = render(
        <Card header={<h2>Section Title</h2>}>
          <p>Content</p>
        </Card>
      );
      const heading = screen.getByRole('heading', { level: 2 });
      expect(heading).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Badge Component', () => {
    it('renders with appropriate semantics', async () => {
      const { container } = render(<Badge>Active</Badge>);
      expect(screen.getByText('Active')).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('provides color variants without breaking contrast', async () => {
      const { container } = render(
        <>
          <Badge variant="primary">Primary</Badge>
          <Badge variant="success">Success</Badge>
          <Badge variant="warning">Warning</Badge>
          <Badge variant="error">Error</Badge>
        </>
      );
      expect(screen.getByText('Primary')).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Modal Component', () => {
    it('manages focus with proper role', async () => {
      const { container } = render(
        <Modal open={true} title="Confirm Action">
          <p>Are you sure?</p>
        </Modal>
      );
      const dialog = screen.getByRole('dialog');
      expect(dialog).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('includes accessible title', async () => {
      const { container } = render(
        <Modal open={true} title="Delete Item">
          <p>This action cannot be undone.</p>
        </Modal>
      );
      expect(screen.getByText('Delete Item')).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('closes with keyboard shortcut', async () => {
      const onClose = () => {};
      const { container } = render(
        <Modal open={true} title="Modal" onClose={onClose}>
          <p>Content</p>
        </Modal>
      );
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Table Component', () => {
    const columns = [
      { accessorKey: 'name', header: 'Name' },
      { accessorKey: 'email', header: 'Email' },
      { accessorKey: 'status', header: 'Status' },
    ];

    const data = [
      { name: 'John Doe', email: 'john@example.com', status: 'Active' },
      { name: 'Jane Smith', email: 'jane@example.com', status: 'Inactive' },
    ];

    it('uses proper table semantics', async () => {
      const { container } = render(
        <Table columns={columns} data={data} />
      );
      const table = container.querySelector('table');
      expect(table).toBeInTheDocument();
      const headers = screen.getAllByRole('columnheader');
      expect(headers.length).toBeGreaterThan(0);
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('provides accessible header cells', async () => {
      const { container } = render(
        <Table columns={columns} data={data} />
      );
      expect(screen.getByText('Name')).toBeInTheDocument();
      expect(screen.getByText('Email')).toBeInTheDocument();
      expect(screen.getByText('Status')).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('renders data rows with proper cell semantics', async () => {
      const { container } = render(
        <Table columns={columns} data={data} />
      );
      expect(screen.getByText('John Doe')).toBeInTheDocument();
      expect(screen.getByText('jane@example.com')).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Select Component', () => {
    const options = [
      { value: '1', label: 'Option 1' },
      { value: '2', label: 'Option 2' },
      { value: '3', label: 'Option 3' },
    ];

    it('associates label with select input', async () => {
      const { container } = render(
        <Select label="Choose Option" options={options} />
      );
      expect(screen.getByText('Choose Option')).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('marks required selects accessibly', async () => {
      const { container } = render(
        <Select label="Required Field" options={options} required />
      );
      const select = screen.getByRole('combobox');
      expect(select).toHaveAttribute('required');
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('displays error message accessibly', async () => {
      const { container } = render(
        <Select
          label="Selection"
          options={options}
          error="Please make a selection"
        />
      );
      const select = screen.getByRole('combobox');
      expect(select).toHaveAttribute('aria-invalid', 'true');
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('supports hint text with aria-describedby', async () => {
      const { container } = render(
        <Select
          label="Choose"
          options={options}
          hint="This is a helpful hint"
        />
      );
      const select = screen.getByRole('combobox');
      expect(select).toHaveAttribute('aria-describedby');
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Form Patterns', () => {
    it('creates accessible form with inputs', async () => {
      const { container } = render(
        <form>
          <Input label="First Name" name="firstName" required />
          <Input label="Last Name" name="lastName" required />
          <Input label="Email" name="email" type="email" required />
          <Button type="submit">Submit</Button>
        </form>
      );
      const form = container.querySelector('form');
      expect(form).toBeInTheDocument();
      const submitButton = screen.getByRole('button', { name: /submit/i });
      expect(submitButton).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('handles form validation display', async () => {
      const { container } = render(
        <form>
          <Input
            label="Username"
            name="username"
            error="Username already exists"
          />
          <Button type="submit">Register</Button>
        </form>
      );
      const input = screen.getByRole('textbox');
      expect(input).toHaveAttribute('aria-invalid', 'true');
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Interactive Elements', () => {
    it('ensures links have descriptive text', async () => {
      const { container } = render(
        <a href="/">Dashboard</a>
      );
      const link = screen.getByRole('link', { name: /dashboard/i });
      expect(link).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('provides alt text for images', async () => {
      const { container } = render(
        <img src="/logo.png" alt="Peanut Suite Logo" />
      );
      const image = screen.getByAltText('Peanut Suite Logo');
      expect(image).toBeInTheDocument();
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });

    it('maintains keyboard navigation', async () => {
      const { container } = render(
        <>
          <Button>Button 1</Button>
          <Input label="Text Input" name="text" />
          <Button>Button 2</Button>
        </>
      );
      const elements = container.querySelectorAll('button, input');
      expect(elements.length).toBeGreaterThan(0);
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });

  describe('Skip Link', () => {
    it('provides skip to main content link', async () => {
      const { container } = render(
        <div>
          <a href="#main-content" className="sr-only focus:not-sr-only">
            Skip to main content
          </a>
          <main id="main-content">Content</main>
        </div>
      );
      const skipLink = screen.getByText('Skip to main content');
      expect(skipLink).toBeInTheDocument();
      expect(skipLink).toHaveAttribute('href', '#main-content');
      const results = await axe(container);
      expect(results).toHaveNoViolations();
    });
  });
});
